<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\AutoDealership;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskResponse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TaskConcurrentModificationTest extends TestCase
{
    use RefreshDatabase;

    private AutoDealership $dealership;

    private User $manager;

    private User $employee1;

    private User $employee2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dealership = AutoDealership::factory()->create();
        $this->manager = User::factory()->create([
            'role' => Role::MANAGER->value,
            'dealership_id' => $this->dealership->id,
        ]);
        $this->employee1 = User::factory()->create([
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $this->dealership->id,
        ]);
        $this->employee2 = User::factory()->create([
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $this->dealership->id,
        ]);
    }

    public function test_two_employees_can_submit_status_sequentially(): void
    {
        Queue::fake();

        $task = Task::factory()->group()->completion()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
        ]);

        TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee1->id]);
        TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee2->id]);

        // Employee 1 submits
        $response1 = $this->actingAs($this->employee1, 'sanctum')
            ->patchJson("/api/v1/tasks/{$task->id}/status", [
                'status' => 'pending_review',
            ]);
        $response1->assertOk();

        // Employee 2 submits
        $response2 = $this->actingAs($this->employee2, 'sanctum')
            ->patchJson("/api/v1/tasks/{$task->id}/status", [
                'status' => 'pending_review',
            ]);
        $response2->assertOk();

        // Both responses should exist
        $this->assertDatabaseCount('task_responses', 2);
        $this->assertEquals(2, TaskResponse::where('task_id', $task->id)->where('status', 'pending_review')->count());
    }

    public function test_manager_update_does_not_affect_other_manager_actions(): void
    {
        $manager2 = User::factory()->create([
            'role' => Role::MANAGER->value,
            'dealership_id' => $this->dealership->id,
        ]);

        $task = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'title' => 'Original Title',
        ]);

        // Manager 1 updates
        $response1 = $this->actingAs($this->manager, 'sanctum')
            ->putJson("/api/v1/tasks/{$task->id}", [
                'title' => 'Updated by Manager 1',
            ]);
        $response1->assertOk();

        // Manager 2 updates
        $response2 = $this->actingAs($manager2, 'sanctum')
            ->putJson("/api/v1/tasks/{$task->id}", [
                'title' => 'Updated by Manager 2',
            ]);
        $response2->assertOk();

        $task->refresh();
        $this->assertEquals('Updated by Manager 2', $task->title);
    }

    public function test_concurrent_approve_and_reject_for_different_responses(): void
    {
        Queue::fake();

        $task = Task::factory()->group()->completion()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
        ]);

        TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee1->id]);
        TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee2->id]);

        // Employees submit via API (ensures correct response state)
        $r1 = $this->actingAs($this->employee1, 'sanctum')
            ->patchJson("/api/v1/tasks/{$task->id}/status", ['status' => 'pending_review']);
        $r1->assertOk();

        $r2 = $this->actingAs($this->employee2, 'sanctum')
            ->patchJson("/api/v1/tasks/{$task->id}/status", ['status' => 'pending_review']);
        $r2->assertOk();

        $resp1 = TaskResponse::where('task_id', $task->id)->where('user_id', $this->employee1->id)->firstOrFail();
        $resp2 = TaskResponse::where('task_id', $task->id)->where('user_id', $this->employee2->id)->firstOrFail();

        // Approve employee1's response
        $response1 = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/task-responses/{$resp1->id}/approve");
        $response1->assertOk();

        // Reject employee2's response
        $response2 = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/task-responses/{$resp2->id}/reject", [
                'reason' => 'Некачественное фото',
            ]);
        $response2->assertOk();

        $resp1->refresh();
        $resp2->refresh();
        $this->assertEquals('completed', $resp1->status);
        $this->assertEquals('rejected', $resp2->status);
    }

    public function test_sync_assignments_replaces_all_at_once(): void
    {
        $task = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
        ]);

        TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee1->id]);
        TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee2->id]);

        $employee3 = User::factory()->create([
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $this->dealership->id,
        ]);

        // Replace assignments: remove employee2, add employee3
        $response = $this->actingAs($this->manager, 'sanctum')
            ->putJson("/api/v1/tasks/{$task->id}", [
                'assignments' => [$this->employee1->id, $employee3->id],
            ]);
        $response->assertOk();

        $task->refresh();
        $assignedIds = $task->assignments()->whereNull('deleted_at')->pluck('user_id')->sort()->values()->toArray();
        $this->assertEqualsCanonicalizing([$this->employee1->id, $employee3->id], $assignedIds);
    }

    public function test_delete_and_update_on_same_task_first_wins(): void
    {
        $task = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
        ]);
        $taskId = $task->id;

        // Delete first
        $deleteResponse = $this->actingAs($this->manager, 'sanctum')
            ->deleteJson("/api/v1/tasks/{$taskId}");
        $deleteResponse->assertOk();

        // Try to update the deleted task
        $updateResponse = $this->actingAs($this->manager, 'sanctum')
            ->putJson("/api/v1/tasks/{$taskId}", [
                'title' => 'Updated after delete',
            ]);

        // Should get 403 or 404 (policy denies access to soft-deleted tasks)
        $this->assertContains($updateResponse->getStatusCode(), [403, 404]);
    }

    public function test_multiple_managers_can_see_same_task_simultaneously(): void
    {
        $manager2 = User::factory()->create([
            'role' => Role::MANAGER->value,
            'dealership_id' => $this->dealership->id,
        ]);

        $task = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
        ]);

        // Both managers can view the same task
        $response1 = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/tasks/{$task->id}");
        $response1->assertOk();

        $response2 = $this->actingAs($manager2, 'sanctum')
            ->getJson("/api/v1/tasks/{$task->id}");
        $response2->assertOk();

        $this->assertEquals($response1->json('data.id'), $response2->json('data.id'));
    }

    public function test_reassign_after_soft_delete_restores_assignment(): void
    {
        $task = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
        ]);

        // Assign employee1
        $this->actingAs($this->manager, 'sanctum')
            ->putJson("/api/v1/tasks/{$task->id}", [
                'assignments' => [$this->employee1->id],
            ]);

        // Remove employee1
        $this->actingAs($this->manager, 'sanctum')
            ->putJson("/api/v1/tasks/{$task->id}", [
                'assignments' => [$this->employee2->id],
            ]);

        // Re-add employee1 (should restore soft-deleted assignment)
        $this->actingAs($this->manager, 'sanctum')
            ->putJson("/api/v1/tasks/{$task->id}", [
                'assignments' => [$this->employee1->id, $this->employee2->id],
            ]);

        $task->refresh();
        $activeAssignments = $task->assignments()->whereNull('deleted_at')->count();
        $this->assertEquals(2, $activeAssignments);
    }
}
