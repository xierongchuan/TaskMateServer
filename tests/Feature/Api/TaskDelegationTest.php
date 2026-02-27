<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\AutoDealership;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskDelegation;
use App\Models\TaskResponse;
use App\Models\User;
use Carbon\Carbon;

describe('Task Delegation API', function () {
    beforeEach(function () {
        $this->dealership = AutoDealership::factory()->create();
        $this->owner = User::factory()->create([
            'role' => Role::OWNER->value,
            'dealership_id' => $this->dealership->id,
        ]);
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
        $this->employee3 = User::factory()->create([
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $this->dealership->id,
        ]);
    });

    describe('POST /api/v1/tasks/{task}/delegations', function () {
        it('allows employee to create delegation request', function () {
            $task = Task::factory()->individual()->completion()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee1->id]);

            $response = $this->actingAs($this->employee1, 'sanctum')
                ->postJson("/api/v1/tasks/{$task->id}/delegations", [
                    'to_user_id' => $this->employee2->id,
                    'reason' => 'Ухожу в отпуск',
                ]);

            $response->assertStatus(201)
                ->assertJsonPath('message', 'Запрос на делегирование создан')
                ->assertJsonPath('data.status', 'pending')
                ->assertJsonPath('data.from_user_id', $this->employee1->id)
                ->assertJsonPath('data.to_user_id', $this->employee2->id)
                ->assertJsonPath('data.reason', 'Ухожу в отпуск');

            $this->assertDatabaseHas('task_delegations', [
                'task_id' => $task->id,
                'from_user_id' => $this->employee1->id,
                'to_user_id' => $this->employee2->id,
                'status' => 'pending',
            ]);
        });

        it('allows delegation without reason', function () {
            $task = Task::factory()->individual()->completion()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee1->id]);

            $response = $this->actingAs($this->employee1, 'sanctum')
                ->postJson("/api/v1/tasks/{$task->id}/delegations", [
                    'to_user_id' => $this->employee2->id,
                ]);

            $response->assertStatus(201);
            $this->assertDatabaseHas('task_delegations', [
                'task_id' => $task->id,
                'reason' => null,
            ]);
        });

        it('returns 422 when delegating to self', function () {
            $task = Task::factory()->individual()->completion()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee1->id]);

            $response = $this->actingAs($this->employee1, 'sanctum')
                ->postJson("/api/v1/tasks/{$task->id}/delegations", [
                    'to_user_id' => $this->employee1->id,
                ]);

            $response->assertStatus(422);
        });

        it('returns 403 when manager tries to create delegation', function () {
            $task = Task::factory()->individual()->completion()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);

            $response = $this->actingAs($this->manager, 'sanctum')
                ->postJson("/api/v1/tasks/{$task->id}/delegations", [
                    'to_user_id' => $this->employee2->id,
                ]);

            $response->assertStatus(403);
        });

        it('returns 422 when target is not employee', function () {
            $task = Task::factory()->individual()->completion()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee1->id]);

            $response = $this->actingAs($this->employee1, 'sanctum')
                ->postJson("/api/v1/tasks/{$task->id}/delegations", [
                    'to_user_id' => $this->manager->id,
                ]);

            $response->assertStatus(422);
        });

        it('returns 422 when target is in different dealership', function () {
            $otherDealership = AutoDealership::factory()->create();
            $otherEmployee = User::factory()->create([
                'role' => Role::EMPLOYEE->value,
                'dealership_id' => $otherDealership->id,
            ]);

            $task = Task::factory()->individual()->completion()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee1->id]);

            $response = $this->actingAs($this->employee1, 'sanctum')
                ->postJson("/api/v1/tasks/{$task->id}/delegations", [
                    'to_user_id' => $otherEmployee->id,
                ]);

            $response->assertStatus(422);
        });

        it('returns 422 when task response is completed', function () {
            $task = Task::factory()->individual()->completion()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee1->id]);
            TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee1->id,
                'status' => 'completed',
                'responded_at' => Carbon::now(),
            ]);

            $response = $this->actingAs($this->employee1, 'sanctum')
                ->postJson("/api/v1/tasks/{$task->id}/delegations", [
                    'to_user_id' => $this->employee2->id,
                ]);

            $response->assertStatus(422)
                ->assertJsonPath('message', 'Нельзя делегировать задачу, которая уже выполнена или на проверке');
        });

        it('returns 422 when task response is pending_review', function () {
            $task = Task::factory()->individual()->completionWithProof()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee1->id]);
            TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee1->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);

            $response = $this->actingAs($this->employee1, 'sanctum')
                ->postJson("/api/v1/tasks/{$task->id}/delegations", [
                    'to_user_id' => $this->employee2->id,
                ]);

            $response->assertStatus(422);
        });

        it('returns 422 when pending delegation already exists', function () {
            $task = Task::factory()->individual()->completion()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee1->id]);

            // Создаём первый запрос
            TaskDelegation::create([
                'task_id' => $task->id,
                'from_user_id' => $this->employee1->id,
                'to_user_id' => $this->employee2->id,
                'status' => 'pending',
            ]);

            // Пытаемся создать второй
            $response = $this->actingAs($this->employee1, 'sanctum')
                ->postJson("/api/v1/tasks/{$task->id}/delegations", [
                    'to_user_id' => $this->employee3->id,
                ]);

            $response->assertStatus(422)
                ->assertJsonPath('message', 'У вас уже есть активный запрос на делегирование этой задачи');
        });

        it('returns 422 when target already assigned to group task', function () {
            $task = Task::factory()->group()->completion()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee1->id]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee2->id]);

            $response = $this->actingAs($this->employee1, 'sanctum')
                ->postJson("/api/v1/tasks/{$task->id}/delegations", [
                    'to_user_id' => $this->employee2->id,
                ]);

            $response->assertStatus(422)
                ->assertJsonPath('message', 'Этот сотрудник уже назначен на данную задачу');
        });

        it('allows delegation of overdue tasks', function () {
            $task = Task::factory()->individual()->completion()->overdue()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee1->id]);

            $response = $this->actingAs($this->employee1, 'sanctum')
                ->postJson("/api/v1/tasks/{$task->id}/delegations", [
                    'to_user_id' => $this->employee2->id,
                    'reason' => 'Болею, не могу выполнить',
                ]);

            $response->assertStatus(201);
        });

        it('allows delegation when response is rejected', function () {
            $task = Task::factory()->individual()->completion()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee1->id]);
            TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee1->id,
                'status' => 'rejected',
                'responded_at' => Carbon::now(),
                'rejection_reason' => 'Неверное доказательство',
            ]);

            $response = $this->actingAs($this->employee1, 'sanctum')
                ->postJson("/api/v1/tasks/{$task->id}/delegations", [
                    'to_user_id' => $this->employee2->id,
                ]);

            $response->assertStatus(201);
        });

        it('returns 422 when employee is not assigned to task', function () {
            $task = Task::factory()->individual()->completion()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            // employee1 не назначен

            $response = $this->actingAs($this->employee1, 'sanctum')
                ->postJson("/api/v1/tasks/{$task->id}/delegations", [
                    'to_user_id' => $this->employee2->id,
                ]);

            $response->assertStatus(422)
                ->assertJsonPath('message', 'Вы не назначены на эту задачу');
        });
    });

    describe('POST /api/v1/task-delegations/{id}/accept', function () {
        it('reassigns task when target accepts', function () {
            $task = Task::factory()->individual()->completion()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee1->id]);
            $delegation = TaskDelegation::create([
                'task_id' => $task->id,
                'from_user_id' => $this->employee1->id,
                'to_user_id' => $this->employee2->id,
                'status' => 'pending',
            ]);

            $response = $this->actingAs($this->employee2, 'sanctum')
                ->postJson("/api/v1/task-delegations/{$delegation->id}/accept");

            $response->assertStatus(200)
                ->assertJsonPath('message', 'Делегирование принято')
                ->assertJsonPath('data.status', 'accepted');

            // to_user назначен
            $this->assertDatabaseHas('task_assignments', [
                'task_id' => $task->id,
                'user_id' => $this->employee2->id,
                'deleted_at' => null,
            ]);

            // from_user soft-deleted
            $this->assertSoftDeleted('task_assignments', [
                'task_id' => $task->id,
                'user_id' => $this->employee1->id,
            ]);
        });

        it('removes from_user pending response on accept', function () {
            $task = Task::factory()->individual()->completion()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee1->id]);
            TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee1->id,
                'status' => 'pending',
                'responded_at' => Carbon::now(),
            ]);
            $delegation = TaskDelegation::create([
                'task_id' => $task->id,
                'from_user_id' => $this->employee1->id,
                'to_user_id' => $this->employee2->id,
                'status' => 'pending',
            ]);

            $this->actingAs($this->employee2, 'sanctum')
                ->postJson("/api/v1/task-delegations/{$delegation->id}/accept");

            $this->assertDatabaseMissing('task_responses', [
                'task_id' => $task->id,
                'user_id' => $this->employee1->id,
            ]);
        });

        it('removes from_user rejected response on accept', function () {
            $task = Task::factory()->individual()->completion()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee1->id]);
            TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee1->id,
                'status' => 'rejected',
                'responded_at' => Carbon::now(),
            ]);
            $delegation = TaskDelegation::create([
                'task_id' => $task->id,
                'from_user_id' => $this->employee1->id,
                'to_user_id' => $this->employee2->id,
                'status' => 'pending',
            ]);

            $this->actingAs($this->employee2, 'sanctum')
                ->postJson("/api/v1/task-delegations/{$delegation->id}/accept");

            $this->assertDatabaseMissing('task_responses', [
                'task_id' => $task->id,
                'user_id' => $this->employee1->id,
            ]);
        });

        it('works for group tasks with partial reassignment', function () {
            $task = Task::factory()->group()->completion()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee1->id]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee3->id]);

            $delegation = TaskDelegation::create([
                'task_id' => $task->id,
                'from_user_id' => $this->employee1->id,
                'to_user_id' => $this->employee2->id,
                'status' => 'pending',
            ]);

            $this->actingAs($this->employee2, 'sanctum')
                ->postJson("/api/v1/task-delegations/{$delegation->id}/accept")
                ->assertStatus(200);

            // employee1 removed, employee2 added, employee3 still there
            $this->assertSoftDeleted('task_assignments', [
                'task_id' => $task->id,
                'user_id' => $this->employee1->id,
            ]);
            $this->assertDatabaseHas('task_assignments', [
                'task_id' => $task->id,
                'user_id' => $this->employee2->id,
                'deleted_at' => null,
            ]);
            $this->assertDatabaseHas('task_assignments', [
                'task_id' => $task->id,
                'user_id' => $this->employee3->id,
                'deleted_at' => null,
            ]);
        });

        it('returns 403 when non-target tries to accept', function () {
            $task = Task::factory()->individual()->completion()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee1->id]);
            $delegation = TaskDelegation::create([
                'task_id' => $task->id,
                'from_user_id' => $this->employee1->id,
                'to_user_id' => $this->employee2->id,
                'status' => 'pending',
            ]);

            // employee1 (initiator) tries to accept — should fail
            $response = $this->actingAs($this->employee1, 'sanctum')
                ->postJson("/api/v1/task-delegations/{$delegation->id}/accept");

            $response->assertStatus(403);
        });

        it('returns 422 when delegation already processed', function () {
            $task = Task::factory()->individual()->completion()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            $delegation = TaskDelegation::create([
                'task_id' => $task->id,
                'from_user_id' => $this->employee1->id,
                'to_user_id' => $this->employee2->id,
                'status' => 'accepted',
                'responded_at' => Carbon::now(),
            ]);

            $response = $this->actingAs($this->employee2, 'sanctum')
                ->postJson("/api/v1/task-delegations/{$delegation->id}/accept");

            $response->assertStatus(422)
                ->assertJsonPath('message', 'Этот запрос уже обработан');
        });

        it('returns 404 for non-existent delegation', function () {
            $response = $this->actingAs($this->employee2, 'sanctum')
                ->postJson('/api/v1/task-delegations/99999/accept');

            $response->assertStatus(404);
        });
    });

    describe('POST /api/v1/task-delegations/{id}/reject', function () {
        it('allows target to reject with reason', function () {
            $task = Task::factory()->individual()->completion()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee1->id]);
            $delegation = TaskDelegation::create([
                'task_id' => $task->id,
                'from_user_id' => $this->employee1->id,
                'to_user_id' => $this->employee2->id,
                'status' => 'pending',
            ]);

            $response = $this->actingAs($this->employee2, 'sanctum')
                ->postJson("/api/v1/task-delegations/{$delegation->id}/reject", [
                    'reason' => 'У меня слишком много задач',
                ]);

            $response->assertStatus(200)
                ->assertJsonPath('message', 'Делегирование отклонено')
                ->assertJsonPath('data.status', 'rejected')
                ->assertJsonPath('data.rejection_reason', 'У меня слишком много задач');

            // Assignment не изменился
            $this->assertDatabaseHas('task_assignments', [
                'task_id' => $task->id,
                'user_id' => $this->employee1->id,
                'deleted_at' => null,
            ]);
        });

        it('requires reason for rejection', function () {
            $task = Task::factory()->individual()->completion()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            $delegation = TaskDelegation::create([
                'task_id' => $task->id,
                'from_user_id' => $this->employee1->id,
                'to_user_id' => $this->employee2->id,
                'status' => 'pending',
            ]);

            $response = $this->actingAs($this->employee2, 'sanctum')
                ->postJson("/api/v1/task-delegations/{$delegation->id}/reject", []);

            $response->assertStatus(422);
        });

        it('returns 403 when non-target tries to reject', function () {
            $task = Task::factory()->individual()->completion()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            $delegation = TaskDelegation::create([
                'task_id' => $task->id,
                'from_user_id' => $this->employee1->id,
                'to_user_id' => $this->employee2->id,
                'status' => 'pending',
            ]);

            $response = $this->actingAs($this->employee1, 'sanctum')
                ->postJson("/api/v1/task-delegations/{$delegation->id}/reject", [
                    'reason' => 'Не хочу',
                ]);

            $response->assertStatus(403);
        });
    });

    describe('POST /api/v1/task-delegations/{id}/cancel', function () {
        it('allows initiator to cancel pending delegation', function () {
            $task = Task::factory()->individual()->completion()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            $delegation = TaskDelegation::create([
                'task_id' => $task->id,
                'from_user_id' => $this->employee1->id,
                'to_user_id' => $this->employee2->id,
                'status' => 'pending',
            ]);

            $response = $this->actingAs($this->employee1, 'sanctum')
                ->postJson("/api/v1/task-delegations/{$delegation->id}/cancel");

            $response->assertStatus(200)
                ->assertJsonPath('message', 'Запрос на делегирование отменён')
                ->assertJsonPath('data.status', 'cancelled');

            $this->assertDatabaseHas('task_delegations', [
                'id' => $delegation->id,
                'status' => 'cancelled',
                'cancelled_by' => $this->employee1->id,
            ]);
        });

        it('allows manager to cancel delegation in their dealership', function () {
            $task = Task::factory()->individual()->completion()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            $delegation = TaskDelegation::create([
                'task_id' => $task->id,
                'from_user_id' => $this->employee1->id,
                'to_user_id' => $this->employee2->id,
                'status' => 'pending',
            ]);

            $response = $this->actingAs($this->manager, 'sanctum')
                ->postJson("/api/v1/task-delegations/{$delegation->id}/cancel");

            $response->assertStatus(200);
            $this->assertDatabaseHas('task_delegations', [
                'id' => $delegation->id,
                'status' => 'cancelled',
                'cancelled_by' => $this->manager->id,
            ]);
        });

        it('allows owner to cancel any delegation', function () {
            $task = Task::factory()->individual()->completion()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            $delegation = TaskDelegation::create([
                'task_id' => $task->id,
                'from_user_id' => $this->employee1->id,
                'to_user_id' => $this->employee2->id,
                'status' => 'pending',
            ]);

            $response = $this->actingAs($this->owner, 'sanctum')
                ->postJson("/api/v1/task-delegations/{$delegation->id}/cancel");

            $response->assertStatus(200);
        });

        it('returns 403 when unrelated employee tries to cancel', function () {
            $task = Task::factory()->individual()->completion()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            $delegation = TaskDelegation::create([
                'task_id' => $task->id,
                'from_user_id' => $this->employee1->id,
                'to_user_id' => $this->employee2->id,
                'status' => 'pending',
            ]);

            $response = $this->actingAs($this->employee3, 'sanctum')
                ->postJson("/api/v1/task-delegations/{$delegation->id}/cancel");

            $response->assertStatus(403);
        });

        it('returns 422 when cancelling already accepted delegation', function () {
            $task = Task::factory()->individual()->completion()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            $delegation = TaskDelegation::create([
                'task_id' => $task->id,
                'from_user_id' => $this->employee1->id,
                'to_user_id' => $this->employee2->id,
                'status' => 'accepted',
                'responded_at' => Carbon::now(),
            ]);

            $response = $this->actingAs($this->employee1, 'sanctum')
                ->postJson("/api/v1/task-delegations/{$delegation->id}/cancel");

            $response->assertStatus(422);
        });
    });

    describe('GET /api/v1/task-delegations', function () {
        it('returns incoming delegations for employee', function () {
            $task = Task::factory()->individual()->completion()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            // Incoming for employee2
            TaskDelegation::create([
                'task_id' => $task->id,
                'from_user_id' => $this->employee1->id,
                'to_user_id' => $this->employee2->id,
                'status' => 'pending',
            ]);
            // Outgoing from employee1 (not visible for employee2 with direction filter)
            TaskDelegation::create([
                'task_id' => $task->id,
                'from_user_id' => $this->employee3->id,
                'to_user_id' => $this->employee1->id,
                'status' => 'pending',
            ]);

            $response = $this->actingAs($this->employee2, 'sanctum')
                ->getJson('/api/v1/task-delegations?direction=incoming');

            $response->assertStatus(200);
            $data = $response->json('data');
            expect($data)->toHaveCount(1);
            expect($data[0]['to_user_id'])->toBe($this->employee2->id);
        });

        it('returns outgoing delegations for employee', function () {
            $task = Task::factory()->individual()->completion()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            TaskDelegation::create([
                'task_id' => $task->id,
                'from_user_id' => $this->employee1->id,
                'to_user_id' => $this->employee2->id,
                'status' => 'pending',
            ]);

            $response = $this->actingAs($this->employee1, 'sanctum')
                ->getJson('/api/v1/task-delegations?direction=outgoing');

            $response->assertStatus(200);
            $data = $response->json('data');
            expect($data)->toHaveCount(1);
            expect($data[0]['from_user_id'])->toBe($this->employee1->id);
        });

        it('filters by status', function () {
            $task = Task::factory()->individual()->completion()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            TaskDelegation::create([
                'task_id' => $task->id,
                'from_user_id' => $this->employee1->id,
                'to_user_id' => $this->employee2->id,
                'status' => 'pending',
            ]);
            TaskDelegation::create([
                'task_id' => $task->id,
                'from_user_id' => $this->employee2->id,
                'to_user_id' => $this->employee1->id,
                'status' => 'accepted',
                'responded_at' => Carbon::now(),
            ]);

            $response = $this->actingAs($this->employee1, 'sanctum')
                ->getJson('/api/v1/task-delegations?status=pending');

            $response->assertStatus(200);
            $data = $response->json('data');
            expect($data)->toHaveCount(1);
            expect($data[0]['status'])->toBe('pending');
        });

        it('manager sees all delegations in dealership', function () {
            $task = Task::factory()->individual()->completion()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            TaskDelegation::create([
                'task_id' => $task->id,
                'from_user_id' => $this->employee1->id,
                'to_user_id' => $this->employee2->id,
                'status' => 'pending',
            ]);

            $response = $this->actingAs($this->manager, 'sanctum')
                ->getJson('/api/v1/task-delegations');

            $response->assertStatus(200);
            expect($response->json('data'))->toHaveCount(1);
        });
    });

    describe('GET /api/v1/task-delegations/{id}', function () {
        it('allows participant to view delegation', function () {
            $task = Task::factory()->individual()->completion()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            $delegation = TaskDelegation::create([
                'task_id' => $task->id,
                'from_user_id' => $this->employee1->id,
                'to_user_id' => $this->employee2->id,
                'status' => 'pending',
            ]);

            $response = $this->actingAs($this->employee1, 'sanctum')
                ->getJson("/api/v1/task-delegations/{$delegation->id}");

            $response->assertStatus(200)
                ->assertJsonPath('data.id', $delegation->id);
        });

        it('returns 403 for unrelated employee', function () {
            $otherDealership = AutoDealership::factory()->create();
            $task = Task::factory()->individual()->completion()->create([
                'dealership_id' => $otherDealership->id,
                'creator_id' => $this->owner->id,
            ]);
            $delegation = TaskDelegation::create([
                'task_id' => $task->id,
                'from_user_id' => $this->employee1->id,
                'to_user_id' => $this->employee2->id,
                'status' => 'pending',
            ]);

            $response = $this->actingAs($this->employee3, 'sanctum')
                ->getJson("/api/v1/task-delegations/{$delegation->id}");

            $response->assertStatus(403);
        });
    });

    describe('auto-cancel on task completion', function () {
        it('cancels pending delegations when user completes task', function () {
            $task = Task::factory()->individual()->completion()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee1->id]);

            $delegation = TaskDelegation::create([
                'task_id' => $task->id,
                'from_user_id' => $this->employee1->id,
                'to_user_id' => $this->employee2->id,
                'status' => 'pending',
            ]);

            // Employee completes the task themselves
            $this->actingAs($this->employee1, 'sanctum')
                ->patchJson("/api/v1/tasks/{$task->id}/status", [
                    'status' => 'completed',
                ]);

            // Delegation should be auto-cancelled
            $this->assertDatabaseHas('task_delegations', [
                'id' => $delegation->id,
                'status' => 'cancelled',
                'cancelled_by' => $this->employee1->id,
            ]);
        });
    });

    describe('task show includes delegations', function () {
        it('includes delegations in task details', function () {
            $task = Task::factory()->individual()->completion()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee1->id]);
            TaskDelegation::create([
                'task_id' => $task->id,
                'from_user_id' => $this->employee1->id,
                'to_user_id' => $this->employee2->id,
                'status' => 'pending',
                'reason' => 'В отпуске',
            ]);

            $response = $this->actingAs($this->employee1, 'sanctum')
                ->getJson("/api/v1/tasks/{$task->id}");

            $response->assertStatus(200);
            $delegations = $response->json('delegations');
            expect($delegations)->toHaveCount(1);
            expect($delegations[0]['status'])->toBe('pending');
            expect($delegations[0]['reason'])->toBe('В отпуске');
        });
    });
});
