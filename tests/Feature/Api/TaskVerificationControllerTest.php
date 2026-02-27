<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Task;
use App\Models\TaskResponse;
use App\Models\TaskProof;
use App\Models\TaskSharedProof;
use App\Models\TaskAssignment;
use App\Models\AutoDealership;
use App\Enums\Role;
use Carbon\Carbon;

describe('Task Verification API', function () {
    beforeEach(function () {
        $this->dealership = AutoDealership::factory()->create();
        $this->owner = User::factory()->create([
            'role' => Role::OWNER->value,
            'dealership_id' => $this->dealership->id
        ]);
        $this->manager = User::factory()->create([
            'role' => Role::MANAGER->value,
            'dealership_id' => $this->dealership->id
        ]);
        $this->employee = User::factory()->create([
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $this->dealership->id
        ]);
    });

    describe('POST /api/v1/task-responses/{id}/approve', function () {
        it('allows manager to approve pending_review response', function () {
            // Arrange
            $task = Task::factory()->completion()->create([
                'dealership_id' => $this->dealership->id,
                'task_type' => 'individual',
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);

            // Act
            $apiResponse = $this->actingAs($this->manager, 'sanctum')
                ->postJson("/api/v1/task-responses/{$response->id}/approve");

            // Assert
            $apiResponse->assertStatus(200)
                ->assertJsonPath('message', 'Доказательство одобрено');

            $this->assertDatabaseHas('task_responses', [
                'id' => $response->id,
                'status' => 'completed',
                'verified_by' => $this->manager->id,
            ]);
        });

        it('allows owner to approve pending_review response', function () {
            // Arrange
            $task = Task::factory()->completion()->create([
                'dealership_id' => $this->dealership->id,
            ]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);

            // Act
            $apiResponse = $this->actingAs($this->owner, 'sanctum')
                ->postJson("/api/v1/task-responses/{$response->id}/approve");

            // Assert
            $apiResponse->assertStatus(200);
        });

        it('returns 404 for non-existent response', function () {
            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->postJson('/api/v1/task-responses/99999/approve');

            // Assert
            $response->assertStatus(404)
                ->assertJsonPath('message', 'Ответ на задачу не найден');
        });

        it('returns 422 when response is not pending_review', function () {
            // Arrange
            $task = Task::factory()->create(['dealership_id' => $this->dealership->id]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'completed', // Already completed
                'responded_at' => Carbon::now(),
            ]);

            // Act
            $apiResponse = $this->actingAs($this->manager, 'sanctum')
                ->postJson("/api/v1/task-responses/{$response->id}/approve");

            // Assert
            $apiResponse->assertStatus(422)
                ->assertJsonPath('message', 'Этот ответ не требует верификации');
        });

        it('returns 403 when manager has no access to dealership', function () {
            // Arrange
            $otherDealership = AutoDealership::factory()->create();
            $task = Task::factory()->create(['dealership_id' => $otherDealership->id]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);

            // Act
            $apiResponse = $this->actingAs($this->manager, 'sanctum')
                ->postJson("/api/v1/task-responses/{$response->id}/approve");

            // Assert
            $apiResponse->assertStatus(403);
        });

        it('records verification history on approve', function () {
            // Arrange
            $task = Task::factory()->completion()->create(['dealership_id' => $this->dealership->id]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);

            // Act
            $this->actingAs($this->manager, 'sanctum')
                ->postJson("/api/v1/task-responses/{$response->id}/approve");

            // Assert
            $this->assertDatabaseHas('task_verification_history', [
                'task_response_id' => $response->id,
                'action' => 'approved',
                'performed_by' => $this->manager->id,
            ]);
        });
    });

    describe('POST /api/v1/task-responses/{id}/reject', function () {
        it('allows manager to reject pending_review response with reason', function () {
            // Arrange
            $task = Task::factory()->create(['dealership_id' => $this->dealership->id]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);

            // Act
            $apiResponse = $this->actingAs($this->manager, 'sanctum')
                ->postJson("/api/v1/task-responses/{$response->id}/reject", [
                    'reason' => 'Фото нечёткое, пожалуйста переснимите',
                ]);

            // Assert
            $apiResponse->assertStatus(200)
                ->assertJsonPath('message', 'Доказательство отклонено');

            $this->assertDatabaseHas('task_responses', [
                'id' => $response->id,
                'status' => 'rejected',
                'rejection_reason' => 'Фото нечёткое, пожалуйста переснимите',
            ]);
        });

        it('requires reason for rejection', function () {
            // Arrange
            $task = Task::factory()->create(['dealership_id' => $this->dealership->id]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);

            // Act
            $apiResponse = $this->actingAs($this->manager, 'sanctum')
                ->postJson("/api/v1/task-responses/{$response->id}/reject", []);

            // Assert
            $apiResponse->assertStatus(422);
        });

        it('returns 404 for non-existent response', function () {
            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->postJson('/api/v1/task-responses/99999/reject', [
                    'reason' => 'Test reason',
                ]);

            // Assert
            $response->assertStatus(404);
        });

        it('increments rejection_count on reject', function () {
            // Arrange
            $task = Task::factory()->create(['dealership_id' => $this->dealership->id]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
                'rejection_count' => 1,
            ]);

            // Act
            $this->actingAs($this->manager, 'sanctum')
                ->postJson("/api/v1/task-responses/{$response->id}/reject", [
                    'reason' => 'Повторное отклонение',
                ]);

            // Assert
            $this->assertDatabaseHas('task_responses', [
                'id' => $response->id,
                'rejection_count' => 2,
            ]);
        });

        it('records verification history on reject', function () {
            // Arrange
            $task = Task::factory()->create(['dealership_id' => $this->dealership->id]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);

            // Act
            $this->actingAs($this->manager, 'sanctum')
                ->postJson("/api/v1/task-responses/{$response->id}/reject", [
                    'reason' => 'Причина отклонения',
                ]);

            // Assert
            $this->assertDatabaseHas('task_verification_history', [
                'task_response_id' => $response->id,
                'action' => 'rejected',
                'performed_by' => $this->manager->id,
                'reason' => 'Причина отклонения',
            ]);
        });
    });

    describe('POST /api/v1/tasks/{id}/reject-all-responses', function () {
        it('rejects all pending_review responses for a task', function () {
            // Arrange
            $task = Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'task_type' => 'group',
            ]);

            $employee2 = User::factory()->create([
                'role' => Role::EMPLOYEE->value,
                'dealership_id' => $this->dealership->id
            ]);

            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $employee2->id]);

            $response1 = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);
            $response2 = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $employee2->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);

            // Act
            $apiResponse = $this->actingAs($this->manager, 'sanctum')
                ->postJson("/api/v1/tasks/{$task->id}/reject-all-responses", [
                    'reason' => 'Общее отклонение для всех',
                ]);

            // Assert
            $apiResponse->assertStatus(200)
                ->assertJsonPath('message', 'Все ответы отклонены');

            $this->assertDatabaseHas('task_responses', [
                'id' => $response1->id,
                'status' => 'rejected',
            ]);
            $this->assertDatabaseHas('task_responses', [
                'id' => $response2->id,
                'status' => 'rejected',
            ]);
        });

        it('returns 404 for non-existent task', function () {
            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->postJson('/api/v1/tasks/99999/reject-all-responses', [
                    'reason' => 'Test reason',
                ]);

            // Assert
            $response->assertStatus(404)
                ->assertJsonPath('message', 'Задача не найдена');
        });

        it('returns 422 when no pending_review responses', function () {
            // Arrange
            $task = Task::factory()->create(['dealership_id' => $this->dealership->id]);

            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->postJson("/api/v1/tasks/{$task->id}/reject-all-responses", [
                    'reason' => 'Test reason',
                ]);

            // Assert
            $response->assertStatus(422)
                ->assertJsonPath('message', 'Нет ответов, ожидающих проверки');
        });

        it('requires reason for bulk rejection', function () {
            // Arrange
            $task = Task::factory()->create(['dealership_id' => $this->dealership->id]);
            TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);

            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->postJson("/api/v1/tasks/{$task->id}/reject-all-responses", []);

            // Assert
            $response->assertStatus(422);
        });

        it('returns 403 when manager has no access', function () {
            // Arrange
            $otherDealership = AutoDealership::factory()->create();
            $task = Task::factory()->create(['dealership_id' => $otherDealership->id]);
            TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);

            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->postJson("/api/v1/tasks/{$task->id}/reject-all-responses", [
                    'reason' => 'Test reason',
                ]);

            // Assert
            $response->assertStatus(403);
        });
    });
});
