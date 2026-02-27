<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Task;
use App\Models\TaskResponse;
use App\Models\TaskAssignment;
use App\Models\TaskGenerator;
use App\Models\AutoDealership;
use App\Services\TaskFilterService;
use App\Enums\Role;
use Carbon\Carbon;
use Illuminate\Http\Request;

describe('TaskFilterService', function () {
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
        $this->filterService = new TaskFilterService();
    });

    describe('getFilteredTasks', function () {
        it('returns paginated tasks', function () {
            // Arrange
            Task::factory(5)->create(['dealership_id' => $this->dealership->id]);
            $request = Request::create('/api/v1/tasks', 'GET', [
                'dealership_id' => $this->dealership->id,
            ]);

            // Act
            $result = $this->filterService->getFilteredTasks($request, $this->manager);

            // Assert
            expect($result)->toBeInstanceOf(\Illuminate\Contracts\Pagination\LengthAwarePaginator::class);
            expect($result->total())->toBe(5);
        });

        it('respects per_page parameter', function () {
            // Arrange
            Task::factory(10)->create(['dealership_id' => $this->dealership->id]);
            $request = Request::create('/api/v1/tasks', 'GET', [
                'dealership_id' => $this->dealership->id,
                'per_page' => 3,
            ]);

            // Act
            $result = $this->filterService->getFilteredTasks($request, $this->manager);

            // Assert
            expect($result->perPage())->toBe(3);
            expect($result->count())->toBe(3);
        });

        it('excludes archived tasks', function () {
            // Arrange
            Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'archived_at' => Carbon::now(),
            ]);
            Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'archived_at' => null,
            ]);
            $request = Request::create('/api/v1/tasks', 'GET', [
                'dealership_id' => $this->dealership->id,
            ]);

            // Act
            $result = $this->filterService->getFilteredTasks($request, $this->manager);

            // Assert
            expect($result->total())->toBe(1);
        });
    });

    describe('date range filter', function () {
        it('filters by today', function () {
            // Arrange
            Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'deadline' => Carbon::now(),
            ]);
            Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'deadline' => Carbon::now()->addDays(5),
            ]);

            $request = Request::create('/api/v1/tasks', 'GET', [
                'dealership_id' => $this->dealership->id,
                'date_range' => 'today',
            ]);

            // Act
            $result = $this->filterService->getFilteredTasks($request, $this->manager);

            // Assert
            expect($result->total())->toBe(1);
        });

        it('filters completed tasks by responded_at', function () {
            // Arrange
            $task = Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'task_type' => 'individual',
                'deadline' => Carbon::yesterday(),
            ]);
            TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'completed',
                'responded_at' => Carbon::now('UTC'),
            ]);

            $request = Request::create('/api/v1/tasks', 'GET', [
                'dealership_id' => $this->dealership->id,
                'date_range' => 'today',
                'status' => 'completed',
            ]);

            // Act
            $result = $this->filterService->getFilteredTasks($request, $this->manager);

            // Assert
            expect($result->total())->toBe(1);
        });
    });

    describe('dealership filter', function () {
        it('filters by dealership for owner', function () {
            // Arrange
            $otherDealership = AutoDealership::factory()->create();
            Task::factory(2)->create(['dealership_id' => $this->dealership->id]);
            Task::factory(3)->create(['dealership_id' => $otherDealership->id]);

            $request = Request::create('/api/v1/tasks', 'GET', [
                'dealership_id' => $this->dealership->id,
            ]);

            // Act
            $result = $this->filterService->getFilteredTasks($request, $this->owner);

            // Assert
            expect($result->total())->toBe(2);
        });

        it('restricts manager to own dealership', function () {
            // Arrange
            $otherDealership = AutoDealership::factory()->create();
            Task::factory(2)->create(['dealership_id' => $this->dealership->id]);
            Task::factory(3)->create(['dealership_id' => $otherDealership->id]);

            $request = Request::create('/api/v1/tasks', 'GET', [
                'dealership_id' => $otherDealership->id,
            ]);

            // Act
            $result = $this->filterService->getFilteredTasks($request, $this->manager);

            // Assert
            expect($result->total())->toBe(0); // No access
        });
    });

    describe('basic filters', function () {
        it('filters by task_type', function () {
            // Arrange
            Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'task_type' => 'individual',
            ]);
            Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'task_type' => 'group',
            ]);

            $request = Request::create('/api/v1/tasks', 'GET', [
                'dealership_id' => $this->dealership->id,
                'task_type' => 'individual',
            ]);

            // Act
            $result = $this->filterService->getFilteredTasks($request, $this->manager);

            // Assert
            expect($result->total())->toBe(1);
        });

        it('filters by is_active', function () {
            // Arrange
            Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'is_active' => true,
            ]);
            Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'is_active' => false,
            ]);

            $request = Request::create('/api/v1/tasks', 'GET', [
                'dealership_id' => $this->dealership->id,
                'is_active' => 'true',
            ]);

            // Act
            $result = $this->filterService->getFilteredTasks($request, $this->manager);

            // Assert
            expect($result->total())->toBe(1);
        });

        it('filters by creator_id', function () {
            // Arrange
            Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->employee->id,
            ]);

            $request = Request::create('/api/v1/tasks', 'GET', [
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);

            // Act
            $result = $this->filterService->getFilteredTasks($request, $this->manager);

            // Assert
            expect($result->total())->toBe(1);
        });

        it('filters by response_type', function () {
            // Arrange
            Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'response_type' => 'completion',
            ]);
            Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'response_type' => 'notification',
            ]);

            $request = Request::create('/api/v1/tasks', 'GET', [
                'dealership_id' => $this->dealership->id,
                'response_type' => 'completion',
            ]);

            // Act
            $result = $this->filterService->getFilteredTasks($request, $this->manager);

            // Assert
            expect($result->total())->toBe(1);
        });

        it('filters by priority', function () {
            // Arrange
            Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'priority' => 'high',
            ]);
            Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'priority' => 'low',
            ]);

            $request = Request::create('/api/v1/tasks', 'GET', [
                'dealership_id' => $this->dealership->id,
                'priority' => 'high',
            ]);

            // Act
            $result = $this->filterService->getFilteredTasks($request, $this->manager);

            // Assert
            expect($result->total())->toBe(1);
        });

        it('filters by tags', function () {
            // Arrange
            Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'tags' => ['urgent', 'backend'],
            ]);
            Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'tags' => ['frontend'],
            ]);

            $request = Request::create('/api/v1/tasks', 'GET', [
                'dealership_id' => $this->dealership->id,
                'tags' => 'urgent',
            ]);

            // Act
            $result = $this->filterService->getFilteredTasks($request, $this->manager);

            // Assert
            expect($result->total())->toBe(1);
        });

        it('filters by assigned_to', function () {
            // Arrange
            $task1 = Task::factory()->create(['dealership_id' => $this->dealership->id]);
            TaskAssignment::create(['task_id' => $task1->id, 'user_id' => $this->employee->id]);

            $task2 = Task::factory()->create(['dealership_id' => $this->dealership->id]);
            // No assignment

            $request = Request::create('/api/v1/tasks', 'GET', [
                'dealership_id' => $this->dealership->id,
                'assigned_to' => $this->employee->id,
            ]);

            // Act
            $result = $this->filterService->getFilteredTasks($request, $this->manager);

            // Assert
            expect($result->total())->toBe(1);
        });
    });

    describe('deadline filters', function () {
        it('filters by deadline_from', function () {
            // Arrange
            Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'deadline' => Carbon::now()->subDays(2),
            ]);
            Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'deadline' => Carbon::now()->addDays(2),
            ]);

            $request = Request::create('/api/v1/tasks', 'GET', [
                'dealership_id' => $this->dealership->id,
                'deadline_from' => Carbon::now()->toIso8601String(),
            ]);

            // Act
            $result = $this->filterService->getFilteredTasks($request, $this->manager);

            // Assert
            expect($result->total())->toBe(1);
        });

        it('filters by deadline_to', function () {
            // Arrange
            Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'deadline' => Carbon::now()->subDays(2),
            ]);
            Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'deadline' => Carbon::now()->addDays(10),
            ]);

            $request = Request::create('/api/v1/tasks', 'GET', [
                'dealership_id' => $this->dealership->id,
                'deadline_to' => Carbon::now()->addDays(5)->toIso8601String(),
            ]);

            // Act
            $result = $this->filterService->getFilteredTasks($request, $this->manager);

            // Assert
            expect($result->total())->toBe(1);
        });

        it('filters tasks by deadline range', function () {
            // Arrange - БД требует обязательный deadline
            Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'deadline' => Carbon::now()->addDay(),
            ]);
            Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'deadline' => Carbon::now()->addWeek(),
            ]);

            $request = Request::create('/api/v1/tasks', 'GET', [
                'dealership_id' => $this->dealership->id,
                'deadline_from' => Carbon::now()->toDateString(),
                'deadline_to' => Carbon::now()->addDays(2)->toDateString(),
            ]);

            // Act
            $result = $this->filterService->getFilteredTasks($request, $this->manager);

            // Assert - только одна задача попадает в диапазон
            expect($result->total())->toBe(1);
        });
    });

    describe('generator filters', function () {
        it('filters by generator_id', function () {
            // Arrange
            $generator = TaskGenerator::factory()->create([
                'dealership_id' => $this->dealership->id,
            ]);
            Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'generator_id' => $generator->id,
            ]);
            Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'generator_id' => null,
            ]);

            $request = Request::create('/api/v1/tasks', 'GET', [
                'dealership_id' => $this->dealership->id,
                'generator_id' => $generator->id,
            ]);

            // Act
            $result = $this->filterService->getFilteredTasks($request, $this->manager);

            // Assert
            expect($result->total())->toBe(1);
        });

        it('filters by from_generator yes', function () {
            // Arrange
            $generator = TaskGenerator::factory()->create([
                'dealership_id' => $this->dealership->id,
            ]);
            Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'generator_id' => $generator->id,
            ]);
            Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'generator_id' => null,
            ]);

            $request = Request::create('/api/v1/tasks', 'GET', [
                'dealership_id' => $this->dealership->id,
                'from_generator' => 'yes',
            ]);

            // Act
            $result = $this->filterService->getFilteredTasks($request, $this->manager);

            // Assert
            expect($result->total())->toBe(1);
        });

        it('filters by from_generator no', function () {
            // Arrange
            $generator = TaskGenerator::factory()->create([
                'dealership_id' => $this->dealership->id,
            ]);
            Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'generator_id' => $generator->id,
            ]);
            Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'generator_id' => null,
            ]);

            $request = Request::create('/api/v1/tasks', 'GET', [
                'dealership_id' => $this->dealership->id,
                'from_generator' => 'no',
            ]);

            // Act
            $result = $this->filterService->getFilteredTasks($request, $this->manager);

            // Assert
            expect($result->total())->toBe(1);
        });
    });

    describe('search filter', function () {
        it('searches by title', function () {
            // Arrange
            Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'title' => 'Уборка офиса',
            ]);
            Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'title' => 'Проверка документов',
            ]);

            $request = Request::create('/api/v1/tasks', 'GET', [
                'dealership_id' => $this->dealership->id,
                'search' => 'уборка',
            ]);

            // Act
            $result = $this->filterService->getFilteredTasks($request, $this->manager);

            // Assert
            expect($result->total())->toBe(1);
        });

        it('searches by description', function () {
            // Arrange
            Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'title' => 'Задача 1',
                'description' => 'Нужно проверить все автомобили',
            ]);
            Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'title' => 'Задача 2',
                'description' => 'Провести совещание',
            ]);

            $request = Request::create('/api/v1/tasks', 'GET', [
                'dealership_id' => $this->dealership->id,
                'search' => 'автомобили',
            ]);

            // Act
            $result = $this->filterService->getFilteredTasks($request, $this->manager);

            // Assert
            expect($result->total())->toBe(1);
        });
    });

    describe('status filter', function () {
        it('filters by status active', function () {
            // Arrange
            Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'is_active' => true,
            ]);
            Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'is_active' => false,
            ]);

            $request = Request::create('/api/v1/tasks', 'GET', [
                'dealership_id' => $this->dealership->id,
                'status' => 'active',
            ]);

            // Act
            $result = $this->filterService->getFilteredTasks($request, $this->manager);

            // Assert
            expect($result->total())->toBe(1);
        });

        it('filters by status pending_review', function () {
            // Arrange
            $task1 = Task::factory()->create(['dealership_id' => $this->dealership->id]);
            TaskResponse::create([
                'task_id' => $task1->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);

            $task2 = Task::factory()->create(['dealership_id' => $this->dealership->id]);

            $request = Request::create('/api/v1/tasks', 'GET', [
                'dealership_id' => $this->dealership->id,
                'status' => 'pending_review',
            ]);

            // Act
            $result = $this->filterService->getFilteredTasks($request, $this->manager);

            // Assert
            expect($result->total())->toBe(1);
        });

        it('filters by status overdue', function () {
            // Arrange
            Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'is_active' => true,
                'deadline' => Carbon::now()->subDay(), // Overdue
            ]);
            Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'is_active' => true,
                'deadline' => Carbon::now()->addDay(), // Not overdue
            ]);

            $request = Request::create('/api/v1/tasks', 'GET', [
                'dealership_id' => $this->dealership->id,
                'status' => 'overdue',
            ]);

            // Act
            $result = $this->filterService->getFilteredTasks($request, $this->manager);

            // Assert
            expect($result->total())->toBe(1);
        });

        it('filters by status pending', function () {
            // Arrange
            $task1 = Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'is_active' => true,
            ]);
            // No response - pending

            $task2 = Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'is_active' => true,
            ]);
            TaskResponse::create([
                'task_id' => $task2->id,
                'user_id' => $this->employee->id,
                'status' => 'completed',
                'responded_at' => Carbon::now(),
            ]);

            $request = Request::create('/api/v1/tasks', 'GET', [
                'dealership_id' => $this->dealership->id,
                'status' => 'pending',
            ]);

            // Act
            $result = $this->filterService->getFilteredTasks($request, $this->manager);

            // Assert
            expect($result->total())->toBe(1);
        });
    });
});
