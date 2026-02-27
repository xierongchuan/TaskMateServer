<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\AutoDealership;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use App\Services\TaskService;

describe('TaskService Sync Assignments', function () {
    beforeEach(function () {
        $this->dealership = AutoDealership::factory()->create();
        $this->manager = User::factory()->create([
            'role' => Role::MANAGER->value,
            'dealership_id' => $this->dealership->id,
        ]);
        $this->employees = User::factory()->count(5)->create([
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $this->dealership->id,
        ]);
        $this->taskService = app(TaskService::class);
    });

    it('correctly handles soft deleted assignments during sync', function () {
        // Arrange: Создаём задачу и назначаем User A
        $task = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
        ]);
        $userA = $this->employees[0];
        $userB = $this->employees[1];

        $assignmentA = TaskAssignment::create([
            'task_id' => $task->id,
            'user_id' => $userA->id,
        ]);

        // Soft delete assignment A
        $assignmentA->delete();

        // Verify A is soft deleted
        expect(TaskAssignment::find($assignmentA->id))->toBeNull();
        expect(TaskAssignment::withTrashed()->find($assignmentA->id))->not->toBeNull();

        // Act: Sync с [A, B] - A должен быть восстановлен
        $this->taskService->updateTask($task, [
            'assignments' => [$userA->id, $userB->id],
        ]);

        // Assert: A восстановлен (deleted_at = null), не дублирован
        $restoredA = TaskAssignment::where('task_id', $task->id)
            ->where('user_id', $userA->id)
            ->first();
        expect($restoredA)->not->toBeNull();
        expect($restoredA->deleted_at)->toBeNull();

        // Assert: B создан
        $newB = TaskAssignment::where('task_id', $task->id)
            ->where('user_id', $userB->id)
            ->first();
        expect($newB)->not->toBeNull();

        // Assert: Всего 2 записи (не дубликаты)
        $totalAssignments = TaskAssignment::where('task_id', $task->id)->count();
        expect($totalAssignments)->toBe(2);
    });

    it('soft deletes removed assignments instead of hard delete', function () {
        // Arrange
        $task = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
        ]);
        $userA = $this->employees[0];
        $userB = $this->employees[1];

        TaskAssignment::create(['task_id' => $task->id, 'user_id' => $userA->id]);
        TaskAssignment::create(['task_id' => $task->id, 'user_id' => $userB->id]);

        // Act: Sync только с A (убираем B)
        $this->taskService->updateTask($task, [
            'assignments' => [$userA->id],
        ]);

        // Assert: B soft deleted
        $deletedB = TaskAssignment::withTrashed()
            ->where('task_id', $task->id)
            ->where('user_id', $userB->id)
            ->first();
        expect($deletedB)->not->toBeNull();
        expect($deletedB->deleted_at)->not->toBeNull();

        // Assert: A still active
        $activeA = TaskAssignment::where('task_id', $task->id)
            ->where('user_id', $userA->id)
            ->first();
        expect($activeA)->not->toBeNull();
        expect($activeA->deleted_at)->toBeNull();
    });

    it('handles re-delete and re-restore cycle', function () {
        // Arrange
        $task = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
        ]);
        $user = $this->employees[0];

        // Create and soft delete
        $assignment = TaskAssignment::create(['task_id' => $task->id, 'user_id' => $user->id]);
        $assignment->delete();

        // Act 1: Restore by syncing with user
        $this->taskService->updateTask($task, ['assignments' => [$user->id]]);

        // Assert: Restored
        $assignment->refresh();
        expect($assignment->deleted_at)->toBeNull();

        // Act 2: Remove by syncing with empty
        $this->taskService->updateTask($task, ['assignments' => []]);

        // Assert: Soft deleted again
        $assignment->refresh();
        expect($assignment->deleted_at)->not->toBeNull();

        // Act 3: Restore again
        $this->taskService->updateTask($task, ['assignments' => [$user->id]]);

        // Assert: Restored again
        $assignment->refresh();
        expect($assignment->deleted_at)->toBeNull();

        // Only 1 record total (no duplicates)
        $totalRecords = TaskAssignment::withTrashed()
            ->where('task_id', $task->id)
            ->where('user_id', $user->id)
            ->count();
        expect($totalRecords)->toBe(1);
    });

    it('creates new assignments for new users', function () {
        // Arrange
        $task = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
        ]);
        $userA = $this->employees[0];

        TaskAssignment::create(['task_id' => $task->id, 'user_id' => $userA->id]);

        // Act: Add users B and C
        $userB = $this->employees[1];
        $userC = $this->employees[2];

        $this->taskService->updateTask($task, [
            'assignments' => [$userA->id, $userB->id, $userC->id],
        ]);

        // Assert
        $assignments = TaskAssignment::where('task_id', $task->id)->get();
        expect($assignments)->toHaveCount(3);

        $userIds = $assignments->pluck('user_id')->toArray();
        expect($userIds)->toContain($userA->id);
        expect($userIds)->toContain($userB->id);
        expect($userIds)->toContain($userC->id);
    });

    it('handles empty assignments array', function () {
        // Arrange
        $task = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
        ]);
        $user = $this->employees[0];

        TaskAssignment::create(['task_id' => $task->id, 'user_id' => $user->id]);

        // Act: Sync with empty array
        $this->taskService->updateTask($task, ['assignments' => []]);

        // Assert: Assignment soft deleted
        expect(TaskAssignment::where('task_id', $task->id)->count())->toBe(0);
        expect(TaskAssignment::withTrashed()->where('task_id', $task->id)->count())->toBe(1);
    });

    it('does not create duplicate assignments when syncing same user twice', function () {
        // Arrange
        $task = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
        ]);
        $user = $this->employees[0];

        // Act: Sync twice with same user
        $this->taskService->updateTask($task, ['assignments' => [$user->id]]);
        $this->taskService->updateTask($task, ['assignments' => [$user->id]]);

        // Assert: Only 1 assignment
        expect(TaskAssignment::where('task_id', $task->id)->count())->toBe(1);
        expect(TaskAssignment::withTrashed()->where('task_id', $task->id)->count())->toBe(1);
    });
});
