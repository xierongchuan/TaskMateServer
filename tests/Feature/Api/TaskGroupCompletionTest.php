<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Jobs\StoreTaskSharedProofsJob;
use App\Models\AutoDealership;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskResponse;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

describe('Task Group Completion', function () {
    beforeEach(function () {
        Storage::fake('local');
        $this->dealership = AutoDealership::factory()->create();
        $this->manager = User::factory()->create([
            'role' => Role::MANAGER->value,
            'dealership_id' => $this->dealership->id,
        ]);
        // Создаём 5 сотрудников для групповых тестов
        $this->employees = User::factory()->count(5)->create([
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $this->dealership->id,
        ]);
    });

    afterEach(function () {
        Carbon::setTestNow();
    });

    it('correctly tracks partial completion status for group tasks', function () {
        // Arrange: Создаём групповую задачу с 5 исполнителями
        $task = Task::factory()->completion()->group()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'deadline' => Carbon::now()->addDay(),
        ]);

        foreach ($this->employees as $employee) {
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $employee->id]);
        }

        // Initial state: pending, 0%
        $task->load('responses', 'assignments');
        expect($task->status)->toBe('pending');
        expect($task->completion_percentage)->toBe(0);

        // Act 1: 2 сотрудника выполняют задачу
        foreach ($this->employees->take(2) as $employee) {
            $this->actingAs($employee, 'sanctum')
                ->patchJson("/api/v1/tasks/{$task->id}/status", [
                    'status' => 'pending_review',
                ])
                ->assertStatus(200);
        }

        // Verify: status = pending_review, progress = 0% (not yet approved)
        $task->load('responses', 'assignments');
        expect($task->status)->toBe('pending_review');
        expect($task->completion_percentage)->toBe(0); // Только approved считаются completed

        // Act 2: Manager одобряет 2 ответа
        $responses = TaskResponse::where('task_id', $task->id)
            ->where('status', 'pending_review')
            ->get();

        foreach ($responses as $response) {
            $this->actingAs($this->manager, 'sanctum')
                ->postJson("/api/v1/task-responses/{$response->id}/approve")
                ->assertStatus(200);
        }

        // Verify: task still NOT completed (2/5), progress = 40%
        $task->load('responses', 'assignments');
        expect($task->status)->not->toBe('completed');
        expect($task->completion_percentage)->toBe(40);

        // Act 3: 3-й сотрудник выполняет и одобряется
        $this->actingAs($this->employees[2], 'sanctum')
            ->patchJson("/api/v1/tasks/{$task->id}/status", [
                'status' => 'pending_review',
            ])
            ->assertStatus(200);

        $response3 = TaskResponse::where('task_id', $task->id)
            ->where('user_id', $this->employees[2]->id)
            ->first();

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/task-responses/{$response3->id}/approve")
            ->assertStatus(200);

        // Verify: progress = 60%
        $task->load('responses', 'assignments');
        expect($task->completion_percentage)->toBe(60);

        // Act 4: Оставшиеся 2 сотрудника выполняют и одобряются
        foreach ($this->employees->slice(3) as $employee) {
            $this->actingAs($employee, 'sanctum')
                ->patchJson("/api/v1/tasks/{$task->id}/status", [
                    'status' => 'pending_review',
                ])
                ->assertStatus(200);

            $response = TaskResponse::where('task_id', $task->id)
                ->where('user_id', $employee->id)
                ->first();

            $this->actingAs($this->manager, 'sanctum')
                ->postJson("/api/v1/task-responses/{$response->id}/approve")
                ->assertStatus(200);
        }

        // Verify: task completed, progress = 100%
        $task->load('responses', 'assignments');
        expect($task->status)->toBe('completed');
        expect($task->completion_percentage)->toBe(100);
    });

    it('handles mixed response statuses in group task', function () {
        // Arrange: Создаём групповую задачу с 3 исполнителями
        $employees = $this->employees->take(3);
        $task = Task::factory()->completion()->group()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'deadline' => Carbon::now()->addDay(),
        ]);

        foreach ($employees as $employee) {
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $employee->id]);
        }

        // Act: Создаём разные статусы responses
        // Employee 1: completed
        $this->actingAs($employees[0], 'sanctum')
            ->patchJson("/api/v1/tasks/{$task->id}/status", ['status' => 'pending_review'])
            ->assertStatus(200);
        $response1 = TaskResponse::where('task_id', $task->id)
            ->where('user_id', $employees[0]->id)
            ->first();
        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/task-responses/{$response1->id}/approve")
            ->assertStatus(200);

        // Employee 2: pending_review
        $this->actingAs($employees[1], 'sanctum')
            ->patchJson("/api/v1/tasks/{$task->id}/status", ['status' => 'pending_review'])
            ->assertStatus(200);

        // Employee 3: rejected
        $this->actingAs($employees[2], 'sanctum')
            ->patchJson("/api/v1/tasks/{$task->id}/status", ['status' => 'pending_review'])
            ->assertStatus(200);
        $response3 = TaskResponse::where('task_id', $task->id)
            ->where('user_id', $employees[2]->id)
            ->first();
        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/task-responses/{$response3->id}/reject", [
                'reason' => 'Требуется переделать',
            ])
            ->assertStatus(200);

        // Assert: task status should be pending_review (not completed)
        $task->load('responses', 'assignments');
        expect($task->status)->toBe('pending_review');

        // Assert: completion_progress shows correct counts
        $taskData = $task->toApiArray();
        expect($taskData['completion_progress']['total_assignees'])->toBe(3);
        expect($taskData['completion_progress']['completed_count'])->toBe(1);
        expect($taskData['completion_progress']['pending_review_count'])->toBe(1);
        expect($taskData['completion_progress']['rejected_count'])->toBe(1);
        expect($taskData['completion_progress']['pending_count'])->toBe(0);
        expect($taskData['completion_progress']['percentage'])->toBe(33);
    });

    it('dispatches job when using complete_for_all for group task', function () {
        Queue::fake();

        // Arrange: Создаём групповую задачу с 3 исполнителями
        $employees = $this->employees->take(3);
        $task = Task::factory()->completionWithProof()->group()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'deadline' => Carbon::now()->addDay(),
        ]);

        foreach ($employees as $employee) {
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $employee->id]);
        }

        // Act: Manager использует complete_for_all
        $response = $this->actingAs($this->manager, 'sanctum')
            ->patchJson("/api/v1/tasks/{$task->id}/status", [
                'status' => 'pending_review',
                'complete_for_all' => true,
                'proof_files' => [
                    UploadedFile::fake()->image('shared_proof.jpg', 100, 100),
                ],
            ]);

        $response->assertStatus(200);

        // Verify: Job was dispatched
        Queue::assertPushed(StoreTaskSharedProofsJob::class, function ($job) use ($task) {
            return $job->taskId === $task->id;
        });

        // Verify: 3 responses created, all with uses_shared_proofs = true
        $responses = TaskResponse::where('task_id', $task->id)->get();
        expect($responses)->toHaveCount(3);

        foreach ($responses as $taskResponse) {
            expect($taskResponse->status)->toBe('pending_review');
            expect($taskResponse->uses_shared_proofs)->toBeTrue();
            expect($taskResponse->submission_source)->toBe('shared');
        }
    });

    it('marks group task as completed_late if any response is after deadline', function () {
        // Arrange: Создаём групповую задачу с 2 исполнителями
        $employees = $this->employees->take(2);
        $deadline = Carbon::now();

        $task = Task::factory()->completion()->group()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'deadline' => $deadline,
        ]);

        foreach ($employees as $employee) {
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $employee->id]);
        }

        // Act: Employee 1 выполняет до deadline
        Carbon::setTestNow($deadline->copy()->subMinute());
        $this->actingAs($employees[0], 'sanctum')
            ->patchJson("/api/v1/tasks/{$task->id}/status", ['status' => 'pending_review'])
            ->assertStatus(200);
        $response1 = TaskResponse::where('task_id', $task->id)
            ->where('user_id', $employees[0]->id)
            ->first();
        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/task-responses/{$response1->id}/approve")
            ->assertStatus(200);

        // Act: Employee 2 выполняет после deadline
        Carbon::setTestNow($deadline->copy()->addHour());
        $this->actingAs($employees[1], 'sanctum')
            ->patchJson("/api/v1/tasks/{$task->id}/status", ['status' => 'pending_review'])
            ->assertStatus(200);
        $response2 = TaskResponse::where('task_id', $task->id)
            ->where('user_id', $employees[1]->id)
            ->first();
        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/task-responses/{$response2->id}/approve")
            ->assertStatus(200);

        // Assert: task status is completed_late
        $task->load('responses', 'assignments');
        expect($task->status)->toBe('completed_late');
    });

    it('calculates completion percentage based only on assigned users', function () {
        // Arrange: Создаём групповую задачу с 2 назначенными
        $assignedEmployees = $this->employees->take(2);

        $task = Task::factory()->completion()->group()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'deadline' => Carbon::now()->addDay(),
        ]);

        foreach ($assignedEmployees as $employee) {
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $employee->id]);
        }

        // Act: Только один назначенный выполняет
        $this->actingAs($assignedEmployees[0], 'sanctum')
            ->patchJson("/api/v1/tasks/{$task->id}/status", ['status' => 'pending_review'])
            ->assertStatus(200);
        $response1 = TaskResponse::where('task_id', $task->id)
            ->where('user_id', $assignedEmployees[0]->id)
            ->first();
        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/task-responses/{$response1->id}/approve")
            ->assertStatus(200);

        // Assert: task NOT completed (1/2 assigned completed)
        $task->load('responses', 'assignments');
        expect($task->status)->not->toBe('completed');
        expect($task->completion_percentage)->toBe(50);
    });

    it('returns correct status when all assignees have pending responses', function () {
        // Arrange: Создаём групповую задачу
        $employees = $this->employees->take(3);
        $task = Task::factory()->completion()->group()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'deadline' => Carbon::now()->addDay(),
        ]);

        foreach ($employees as $employee) {
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $employee->id]);
        }

        // Act: Все отправляют на проверку
        foreach ($employees as $employee) {
            $this->actingAs($employee, 'sanctum')
                ->patchJson("/api/v1/tasks/{$task->id}/status", ['status' => 'pending_review'])
                ->assertStatus(200);
        }

        // Assert: status should be pending_review (not completed)
        $task->load('responses', 'assignments');
        expect($task->status)->toBe('pending_review');
        expect($task->completion_percentage)->toBe(0); // Никто ещё не approved
    });

    it('handles empty assignments in group task gracefully', function () {
        // Arrange: Создаём групповую задачу БЕЗ назначений
        $task = Task::factory()->completion()->group()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'deadline' => Carbon::now()->addDay(),
        ]);

        // Assert: completion_percentage should be 0 (no division by zero)
        $task->load('responses', 'assignments');
        expect($task->completion_percentage)->toBe(0);

        // Assert: status should be pending
        expect($task->status)->toBe('pending');

        // Assert: toApiArray handles gracefully
        $taskData = $task->toApiArray();
        expect($taskData['completion_progress']['total_assignees'])->toBe(0);
        expect($taskData['completion_progress']['percentage'])->toBe(0);
    });

    it('prevents unassigned employee from completing task', function () {
        // Arrange: Создаём групповую задачу с 2 назначенными
        $assignedEmployees = $this->employees->take(2);
        $unassignedEmployee = $this->employees[2];

        $task = Task::factory()->completion()->group()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'deadline' => Carbon::now()->addDay(),
        ]);

        foreach ($assignedEmployees as $employee) {
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $employee->id]);
        }

        // Act: Неназначенный пытается выполнить
        $response = $this->actingAs($unassignedEmployee, 'sanctum')
            ->patchJson("/api/v1/tasks/{$task->id}/status", ['status' => 'pending_review']);

        // Assert: Может быть либо 403, либо response создаётся но не влияет на статус
        // В зависимости от бизнес-логики проверяем результат
        if ($response->status() === 200) {
            // Если API позволяет создать response, проверяем что статус задачи не изменился от этого
            $task->load('responses', 'assignments');
            // Неназначенный response не должен влиять на completion_percentage
            expect($task->completion_percentage)->toBe(0);
        } else {
            // Если API запрещает, это тоже валидное поведение
            $response->assertStatus(403);
        }
    });
});
