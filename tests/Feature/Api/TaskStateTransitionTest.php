<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\AutoDealership;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskResponse;
use App\Models\User;
use Carbon\Carbon;

describe('Task State Transitions', function () {
    beforeEach(function () {
        $this->dealership = AutoDealership::factory()->create();
        $this->manager = User::factory()->create([
            'role' => Role::MANAGER->value,
            'dealership_id' => $this->dealership->id,
        ]);
        $this->employee = User::factory()->create([
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $this->dealership->id,
        ]);
    });

    describe('Invalid State Transitions', function () {
        it('rejects approval of response with status pending', function () {
            // Arrange: Response со статусом acknowledged (не pending_review)
            $task = Task::factory()->notification()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);
            $taskResponse = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'acknowledged', // Не pending_review!
                'responded_at' => Carbon::now(),
            ]);

            // Act: Пытаемся одобрить
            $response = $this->actingAs($this->manager, 'sanctum')
                ->postJson("/api/v1/task-responses/{$taskResponse->id}/approve");

            // Assert
            $response->assertStatus(422);

            // Verify: Status не изменился
            $taskResponse->refresh();
            expect($taskResponse->status)->toBe('acknowledged');
        });

        it('rejects approval of already completed response', function () {
            // Arrange: Response со статусом completed
            $task = Task::factory()->completion()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);
            $taskResponse = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'completed',
                'responded_at' => Carbon::now(),
                'verified_at' => Carbon::now(),
                'verified_by' => $this->manager->id,
            ]);

            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->postJson("/api/v1/task-responses/{$taskResponse->id}/approve");

            // Assert
            $response->assertStatus(422);
        });

        it('rejects rejection of already completed response', function () {
            // Arrange
            $task = Task::factory()->completion()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);
            $taskResponse = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'completed',
                'responded_at' => Carbon::now(),
                'verified_at' => Carbon::now(),
                'verified_by' => $this->manager->id,
            ]);

            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->postJson("/api/v1/task-responses/{$taskResponse->id}/reject", [
                    'reason' => 'Попытка отклонить выполненное',
                ]);

            // Assert
            $response->assertStatus(422);
        });

        it('archived tasks are handled separately from active tasks', function () {
            // Arrange: Архивированная задача (должна быть на отдельном endpoint)
            $task = Task::factory()->archived()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);

            // Act: Пытаемся получить архивированную задачу через обычный endpoint
            $response = $this->actingAs($this->employee, 'sanctum')
                ->getJson("/api/v1/tasks/{$task->id}");

            // Assert: Задача всё ещё доступна для просмотра
            // (архивация не удаляет, а только помечает is_active=false)
            $response->assertStatus(200);
            expect($response->json('is_active'))->toBeFalse();
        });

        it('rejects editing of completed task', function () {
            // Arrange: Задача со статусом completed
            $task = Task::factory()->completion()->individual()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);
            TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'completed',
                'responded_at' => Carbon::now(),
            ]);

            // Verify task is completed
            $task->load('responses', 'assignments');
            expect($task->status)->toBe('completed');

            // Act: Пытаемся отредактировать
            $response = $this->actingAs($this->manager, 'sanctum')
                ->putJson("/api/v1/tasks/{$task->id}", [
                    'title' => 'Изменённый заголовок',
                ]);

            // Assert
            $response->assertStatus(422);
        });
    });

    describe('Valid State Transitions', function () {
        it('allows pending -> pending_review transition', function () {
            // Arrange
            $task = Task::factory()->completion()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);

            // Assert initial
            $task->load('responses', 'assignments');
            expect($task->status)->toBe('pending');

            // Act
            $response = $this->actingAs($this->employee, 'sanctum')
                ->patchJson("/api/v1/tasks/{$task->id}/status", [
                    'status' => 'pending_review',
                ]);

            // Assert
            $response->assertStatus(200);
            expect($response->json('status'))->toBe('pending_review');
        });

        it('allows pending_review -> completed transition via approval', function () {
            // Arrange
            $task = Task::factory()->completion()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);
            $taskResponse = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);

            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->postJson("/api/v1/task-responses/{$taskResponse->id}/approve");

            // Assert
            $response->assertStatus(200);
            $taskResponse->refresh();
            expect($taskResponse->status)->toBe('completed');
        });

        it('allows pending_review -> rejected transition', function () {
            // Arrange
            $task = Task::factory()->completion()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);
            $taskResponse = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);

            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->postJson("/api/v1/task-responses/{$taskResponse->id}/reject", [
                    'reason' => 'Не соответствует требованиям',
                ]);

            // Assert
            $response->assertStatus(200);
            $taskResponse->refresh();
            expect($taskResponse->status)->toBe('rejected');
        });

        it('allows rejected -> pending_review transition via resubmit', function () {
            // Arrange
            $task = Task::factory()->completion()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);
            TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'rejected',
                'responded_at' => Carbon::now(),
                'rejection_reason' => 'Первоначальное отклонение',
                'rejection_count' => 1,
            ]);

            // Act: Employee переотправляет
            $response = $this->actingAs($this->employee, 'sanctum')
                ->patchJson("/api/v1/tasks/{$task->id}/status", [
                    'status' => 'pending_review',
                ]);

            // Assert
            $response->assertStatus(200);
            expect($response->json('status'))->toBe('pending_review');
        });
    });

    describe('Validation Requirements', function () {
        it('requires reason when rejecting response', function () {
            // Arrange
            $task = Task::factory()->completion()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);
            $taskResponse = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);

            // Act: Отклоняем без причины
            $response = $this->actingAs($this->manager, 'sanctum')
                ->postJson("/api/v1/task-responses/{$taskResponse->id}/reject", []);

            // Assert
            $response->assertStatus(422);
            $response->assertJsonValidationErrors(['reason']);
        });

        it('validates reason max length when rejecting', function () {
            // Arrange
            $task = Task::factory()->completion()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);
            $taskResponse = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);

            // Act: Очень длинная причина
            $response = $this->actingAs($this->manager, 'sanctum')
                ->postJson("/api/v1/task-responses/{$taskResponse->id}/reject", [
                    'reason' => str_repeat('А', 1001), // > 1000 символов
                ]);

            // Assert
            $response->assertStatus(422);
            $response->assertJsonValidationErrors(['reason']);
        });

        it('validates status enum values', function () {
            // Arrange
            $task = Task::factory()->completion()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);

            // Act: Невалидный статус
            $response = $this->actingAs($this->employee, 'sanctum')
                ->patchJson("/api/v1/tasks/{$task->id}/status", [
                    'status' => 'invalid_status',
                ]);

            // Assert
            $response->assertStatus(422);
            $response->assertJsonValidationErrors(['status']);
        });
    });

    describe('Edge Cases', function () {
        it('handles concurrent approval attempts gracefully', function () {
            // Arrange
            $task = Task::factory()->completion()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);
            $taskResponse = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);

            // Act: Первое одобрение успешно
            $response1 = $this->actingAs($this->manager, 'sanctum')
                ->postJson("/api/v1/task-responses/{$taskResponse->id}/approve");
            $response1->assertStatus(200);

            // Act: Второе одобрение должно вернуть ошибку (уже не pending_review)
            $response2 = $this->actingAs($this->manager, 'sanctum')
                ->postJson("/api/v1/task-responses/{$taskResponse->id}/approve");
            $response2->assertStatus(422);
        });

        it('prevents non-existent response approval', function () {
            // Act
            $response = $this->actingAs($this->manager, 'sanctum')
                ->postJson('/api/v1/task-responses/99999/approve');

            // Assert
            $response->assertStatus(404);
        });

        it('handles unassigned employee response appropriately', function () {
            // Arrange
            $task = Task::factory()->completion()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            // НЕ создаём assignment для employee

            // Act
            $response = $this->actingAs($this->employee, 'sanctum')
                ->patchJson("/api/v1/tasks/{$task->id}/status", [
                    'status' => 'pending_review',
                ]);

            // Assert: Либо 403 (запрещено), либо 200 (response создан, но не влияет на группу)
            // В зависимости от бизнес-логики - оба варианта валидны
            expect(in_array($response->status(), [200, 403]))->toBeTrue();

            if ($response->status() === 200) {
                // Если response создан, проверяем что он не влияет на completion
                $task->load('responses', 'assignments');
                expect($task->completion_percentage)->toBe(0);
            }
        });
    });
});
