<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\AutoDealership;
use App\Services\TaskService;
use App\Enums\Role;
use App\Exceptions\DuplicateTaskException;
use Carbon\Carbon;

describe('TaskService', function () {
    beforeEach(function () {
        $this->dealership = AutoDealership::factory()->create();
        $this->manager = User::factory()->create([
            'role' => Role::MANAGER->value,
            'dealership_id' => $this->dealership->id
        ]);
        $this->employee = User::factory()->create([
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $this->dealership->id
        ]);
        $this->owner = User::factory()->create([
            'role' => Role::OWNER->value,
        ]);
        $this->taskService = new TaskService();
    });

    describe('createTask', function () {
        it('creates a task with valid data', function () {
            // Arrange
            $data = [
                'title' => 'Тестовая задача',
                'description' => 'Описание задачи',
                'dealership_id' => $this->dealership->id,
                'task_type' => 'individual',
                'response_type' => 'completion',
                'appear_date' => Carbon::now()->toIso8601String(),
                'deadline' => Carbon::now()->addDay()->toIso8601String(),
                'assignments' => [$this->employee->id],
            ];

            // Act
            $task = $this->taskService->createTask($data, $this->manager);

            // Assert
            expect($task)->toBeInstanceOf(Task::class);
            expect($task->title)->toBe('Тестовая задача');
            expect($task->creator_id)->toBe($this->manager->id);

            $this->assertDatabaseHas('tasks', ['title' => 'Тестовая задача']);
            $this->assertDatabaseHas('task_assignments', [
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
            ]);
        });

        it('creates task without assignments', function () {
            // Arrange
            $data = [
                'title' => 'Задача без назначений',
                'task_type' => 'individual',
                'response_type' => 'notification',
                'dealership_id' => $this->dealership->id,
                'appear_date' => Carbon::now()->toIso8601String(),
                'deadline' => Carbon::now()->addDay()->toIso8601String(),
            ];

            // Act
            $task = $this->taskService->createTask($data, $this->manager);

            // Assert
            expect($task->assignments)->toHaveCount(0);
        });

        it('throws exception for duplicate task', function () {
            // Arrange
            $deadline = Carbon::now()->addDay();
            $data = [
                'title' => 'Уникальная задача',
                'description' => 'Описание',
                'task_type' => 'individual',
                'response_type' => 'completion',
                'dealership_id' => $this->dealership->id,
                'appear_date' => Carbon::now()->toIso8601String(),
                'deadline' => $deadline->toIso8601String(),
            ];

            // Create first task
            $this->taskService->createTask($data, $this->manager);

            // Act & Assert - try to create duplicate
            expect(fn () => $this->taskService->createTask($data, $this->manager))
                ->toThrow(DuplicateTaskException::class);
        });

        it('creates task with priority', function () {
            // Arrange
            $data = [
                'title' => 'Срочная задача',
                'task_type' => 'individual',
                'response_type' => 'completion',
                'dealership_id' => $this->dealership->id,
                'priority' => 'high',
                'appear_date' => Carbon::now()->toIso8601String(),
                'deadline' => Carbon::now()->addDay()->toIso8601String(),
            ];

            // Act
            $task = $this->taskService->createTask($data, $this->manager);

            // Assert
            expect($task->priority)->toBe('high');
        });

        it('creates task with tags', function () {
            // Arrange
            $data = [
                'title' => 'Задача с тегами',
                'task_type' => 'individual',
                'response_type' => 'completion',
                'dealership_id' => $this->dealership->id,
                'tags' => ['срочно', 'важно'],
                'appear_date' => Carbon::now()->toIso8601String(),
                'deadline' => Carbon::now()->addDay()->toIso8601String(),
            ];

            // Act
            $task = $this->taskService->createTask($data, $this->manager);

            // Assert
            expect($task->tags)->toBe(['срочно', 'важно']);
        });
    });

    describe('updateTask', function () {
        it('updates task fields', function () {
            // Arrange
            $task = Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'title' => 'Старое название',
            ]);

            // Act
            $updatedTask = $this->taskService->updateTask($task, [
                'title' => 'Новое название',
                'description' => 'Новое описание',
            ]);

            // Assert
            expect($updatedTask->title)->toBe('Новое название');
            expect($updatedTask->description)->toBe('Новое описание');
        });

        it('syncs assignments on update', function () {
            // Arrange
            $task = Task::factory()->create(['dealership_id' => $this->dealership->id]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);

            $newEmployee = User::factory()->create([
                'role' => Role::EMPLOYEE->value,
                'dealership_id' => $this->dealership->id
            ]);

            // Act
            $this->taskService->updateTask($task, [
                'assignments' => [$newEmployee->id],
            ]);

            // Assert - old assignment soft deleted, new one created
            $this->assertDatabaseHas('task_assignments', [
                'task_id' => $task->id,
                'user_id' => $newEmployee->id,
                'deleted_at' => null,
            ]);
        });

        it('restores previously deleted assignments', function () {
            // Arrange
            $task = Task::factory()->create(['dealership_id' => $this->dealership->id]);
            $assignment = TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);
            $assignment->delete(); // Soft delete

            // Act
            $this->taskService->updateTask($task, [
                'assignments' => [$this->employee->id],
            ]);

            // Assert
            $this->assertDatabaseHas('task_assignments', [
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'deleted_at' => null,
            ]);
        });
    });

    describe('isDuplicate', function () {
        it('returns true for exact duplicate', function () {
            // Arrange
            $deadline = Carbon::now()->addDay();
            Task::factory()->create([
                'title' => 'Тестовая задача',
                'description' => 'Описание',
                'task_type' => 'individual',
                'dealership_id' => $this->dealership->id,
                'deadline' => $deadline,
                'is_active' => true,
            ]);

            // Act
            $isDuplicate = $this->taskService->isDuplicate([
                'title' => 'Тестовая задача',
                'description' => 'Описание',
                'task_type' => 'individual',
                'dealership_id' => $this->dealership->id,
                'deadline' => $deadline->toIso8601String(),
            ]);

            // Assert
            expect($isDuplicate)->toBeTrue();
        });

        it('returns false for different title', function () {
            // Arrange
            Task::factory()->create([
                'title' => 'Задача 1',
                'task_type' => 'individual',
                'dealership_id' => $this->dealership->id,
                'is_active' => true,
            ]);

            // Act
            $isDuplicate = $this->taskService->isDuplicate([
                'title' => 'Задача 2',
                'task_type' => 'individual',
                'dealership_id' => $this->dealership->id,
            ]);

            // Assert
            expect($isDuplicate)->toBeFalse();
        });

        it('returns false for different dealership', function () {
            // Arrange
            $otherDealership = AutoDealership::factory()->create();
            Task::factory()->create([
                'title' => 'Задача',
                'task_type' => 'individual',
                'dealership_id' => $this->dealership->id,
                'is_active' => true,
            ]);

            // Act
            $isDuplicate = $this->taskService->isDuplicate([
                'title' => 'Задача',
                'task_type' => 'individual',
                'dealership_id' => $otherDealership->id,
            ]);

            // Assert
            expect($isDuplicate)->toBeFalse();
        });

        it('returns false for inactive task', function () {
            // Arrange
            Task::factory()->create([
                'title' => 'Неактивная задача',
                'task_type' => 'individual',
                'dealership_id' => $this->dealership->id,
                'is_active' => false,
            ]);

            // Act
            $isDuplicate = $this->taskService->isDuplicate([
                'title' => 'Неактивная задача',
                'task_type' => 'individual',
                'dealership_id' => $this->dealership->id,
            ]);

            // Assert
            expect($isDuplicate)->toBeFalse();
        });
    });

    describe('canAccessDealership', function () {
        it('returns true for owner with any dealership', function () {
            // Act
            $canAccess = $this->taskService->canAccessDealership($this->owner, $this->dealership->id);

            // Assert
            expect($canAccess)->toBeTrue();
        });

        it('returns true for manager with own dealership', function () {
            // Act
            $canAccess = $this->taskService->canAccessDealership($this->manager, $this->dealership->id);

            // Assert
            expect($canAccess)->toBeTrue();
        });

        it('returns false for manager with other dealership', function () {
            // Arrange
            $otherDealership = AutoDealership::factory()->create();

            // Act
            $canAccess = $this->taskService->canAccessDealership($this->manager, $otherDealership->id);

            // Assert
            expect($canAccess)->toBeFalse();
        });

        it('returns true for null dealership', function () {
            // Act
            $canAccess = $this->taskService->canAccessDealership($this->employee, null);

            // Assert
            expect($canAccess)->toBeTrue();
        });
    });

    describe('canEditTask', function () {
        it('returns true for owner', function () {
            // Arrange
            $task = Task::factory()->create(['dealership_id' => $this->dealership->id]);

            // Act
            $canEdit = $this->taskService->canEditTask($this->owner, $task);

            // Assert
            expect($canEdit)->toBeTrue();
        });

        it('returns true for creator', function () {
            // Arrange
            $task = Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->employee->id,
            ]);

            // Act
            $canEdit = $this->taskService->canEditTask($this->employee, $task);

            // Assert
            expect($canEdit)->toBeTrue();
        });

        it('returns true for manager with dealership access', function () {
            // Arrange
            $task = Task::factory()->create(['dealership_id' => $this->dealership->id]);

            // Act
            $canEdit = $this->taskService->canEditTask($this->manager, $task);

            // Assert
            expect($canEdit)->toBeTrue();
        });

        it('returns false for employee without access to dealership', function () {
            // Arrange - создаём задачу в другом автосалоне
            $otherDealership = AutoDealership::factory()->create();
            $task = Task::factory()->create([
                'dealership_id' => $otherDealership->id,
                'creator_id' => $this->manager->id,
            ]);

            // Act - employee не имеет доступа к другому автосалону
            $canEdit = $this->taskService->canEditTask($this->employee, $task);

            // Assert
            expect($canEdit)->toBeFalse();
        });
    });

    describe('canViewTask', function () {
        it('returns true for owner', function () {
            // Arrange
            $task = Task::factory()->create(['dealership_id' => $this->dealership->id]);

            // Act
            $canView = $this->taskService->canViewTask($this->owner, $task);

            // Assert
            expect($canView)->toBeTrue();
        });

        it('returns true for assigned user', function () {
            // Arrange
            $task = Task::factory()->create(['dealership_id' => $this->dealership->id]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);
            $task->load('assignments');

            // Act
            $canView = $this->taskService->canViewTask($this->employee, $task);

            // Assert
            expect($canView)->toBeTrue();
        });

        it('returns true for creator', function () {
            // Arrange
            $task = Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->employee->id,
            ]);
            $task->load('assignments');

            // Act
            $canView = $this->taskService->canViewTask($this->employee, $task);

            // Assert
            expect($canView)->toBeTrue();
        });
    });
});
