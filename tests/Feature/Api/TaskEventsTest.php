<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Events\TaskApproved;
use App\Events\TaskAssigned;
use App\Events\TaskPendingReview;
use App\Events\TaskRejected;
use App\Events\TaskRejectedBulk;
use App\Models\AutoDealership;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskResponse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TaskEventsTest extends TestCase
{
    use RefreshDatabase;

    private AutoDealership $dealership;

    private User $manager;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dealership = AutoDealership::factory()->create();
        $this->manager = User::factory()->create([
            'role' => Role::MANAGER->value,
            'dealership_id' => $this->dealership->id,
        ]);
        $this->employee = User::factory()->create([
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $this->dealership->id,
        ]);
    }

    public function test_task_assigned_event_fired_on_create_with_assignments(): void
    {
        Event::fake([TaskAssigned::class]);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/v1/tasks', [
                'title' => 'Тестовая задача',
                'appear_date' => '2026-04-01T09:00:00Z',
                'deadline' => '2026-04-10T18:00:00Z',
                'task_type' => 'individual',
                'response_type' => 'completion',
                'dealership_id' => $this->dealership->id,
                'assignments' => [$this->employee->id],
            ]);

        $response->assertCreated();
        Event::assertDispatched(TaskAssigned::class);
    }

    public function test_task_pending_review_event_fired_on_submit(): void
    {
        Event::fake([TaskPendingReview::class]);
        Queue::fake();

        $task = Task::factory()->completion()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
        ]);
        TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);

        $this->actingAs($this->employee, 'sanctum')
            ->patchJson("/api/v1/tasks/{$task->id}/status", [
                'status' => 'pending_review',
            ]);

        Event::assertDispatched(TaskPendingReview::class);
    }

    public function test_task_approved_event_fired_on_approve(): void
    {
        Event::fake([TaskApproved::class]);

        $task = Task::factory()->completion()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
        ]);
        TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);

        $response = TaskResponse::create([
            'task_id' => $task->id,
            'user_id' => $this->employee->id,
            'status' => 'pending_review',
            'responded_at' => now(),
        ]);

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/task-responses/{$response->id}/approve");

        Event::assertDispatched(TaskApproved::class);
    }

    public function test_task_rejected_event_fired_on_reject(): void
    {
        Event::fake([TaskRejected::class]);

        $task = Task::factory()->completionWithProof()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
        ]);
        TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);

        $response = TaskResponse::create([
            'task_id' => $task->id,
            'user_id' => $this->employee->id,
            'status' => 'pending_review',
            'responded_at' => now(),
        ]);

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/task-responses/{$response->id}/reject", [
                'reason' => 'Нечёткое фото',
            ]);

        Event::assertDispatched(TaskRejected::class);
    }

    public function test_task_rejected_bulk_event_fired_on_reject_all(): void
    {
        Event::fake([TaskRejectedBulk::class]);
        Queue::fake();

        $task = Task::factory()->group()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
        ]);

        $employee2 = User::factory()->create([
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $this->dealership->id,
        ]);

        TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);
        TaskAssignment::create(['task_id' => $task->id, 'user_id' => $employee2->id]);

        TaskResponse::create([
            'task_id' => $task->id, 'user_id' => $this->employee->id,
            'status' => 'pending_review', 'responded_at' => now(),
        ]);
        TaskResponse::create([
            'task_id' => $task->id, 'user_id' => $employee2->id,
            'status' => 'pending_review', 'responded_at' => now(),
        ]);

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/tasks/{$task->id}/reject-all-responses", [
                'reason' => 'Все фото некачественные',
            ]);

        Event::assertDispatched(TaskRejectedBulk::class);
    }

    public function test_no_event_fired_when_create_without_assignments(): void
    {
        Event::fake([TaskAssigned::class]);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/v1/tasks', [
                'title' => 'Задача без исполнителей',
                'appear_date' => '2026-04-01T09:00:00Z',
                'deadline' => '2026-04-10T18:00:00Z',
                'task_type' => 'individual',
                'response_type' => 'notification',
                'dealership_id' => $this->dealership->id,
            ]);

        $response->assertCreated();
        Event::assertNotDispatched(TaskAssigned::class);
    }
}
