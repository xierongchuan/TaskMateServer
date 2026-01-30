<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Task;
use App\Models\TaskResponse;
use App\Models\TaskGenerator;
use App\Models\Shift;
use App\Models\AutoDealership;
use App\Services\DashboardService;
use App\Enums\Role;
use Carbon\Carbon;

describe('DashboardService', function () {
    beforeEach(function () {
        $this->dealership = AutoDealership::factory()->create();
        $this->employee = User::factory()->create([
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $this->dealership->id
        ]);
        $this->dashboardService = new DashboardService();
    });

    describe('getDashboardData', function () {
        it('returns dashboard data structure', function () {
            // Act
            $data = $this->dashboardService->getDashboardData();

            // Assert
            expect($data)->toHaveKeys([
                'total_users',
                'active_users',
                'total_tasks',
                'active_tasks',
                'completed_tasks',
                'overdue_tasks',
                'overdue_tasks_list',
                'pending_review_count',
                'pending_review_tasks',
                'open_shifts',
                'late_shifts_today',
                'active_shifts',
                'today_tasks_list',
                'active_generators',
                'total_generators',
                'tasks_generated_today',
                'timestamp',
            ]);
        });

        it('counts total users', function () {
            // Arrange
            User::factory(3)->create(['dealership_id' => $this->dealership->id]);

            // Act
            $data = $this->dashboardService->getDashboardData($this->dealership->id);

            // Assert - 3 new users + 1 employee from beforeEach
            expect($data['total_users'])->toBe(4);
        });

        it('counts active tasks', function () {
            // Arrange
            Task::factory(3)->create([
                'dealership_id' => $this->dealership->id,
                'is_active' => true,
            ]);
            Task::factory(2)->create([
                'dealership_id' => $this->dealership->id,
                'is_active' => false,
            ]);

            // Act
            $data = $this->dashboardService->getDashboardData($this->dealership->id);

            // Assert
            expect($data['total_tasks'])->toBe(3);
            expect($data['active_tasks'])->toBe(3);
        });

        it('counts overdue tasks', function () {
            // Arrange
            Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'is_active' => true,
                'deadline' => Carbon::now()->subDay(),
            ]);
            Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'is_active' => true,
                'deadline' => Carbon::now()->addDay(),
            ]);

            // Act
            $data = $this->dashboardService->getDashboardData($this->dealership->id);

            // Assert
            expect($data['overdue_tasks'])->toBe(1);
        });

        it('excludes completed tasks from overdue count', function () {
            // Arrange
            $task = Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'is_active' => true,
                'deadline' => Carbon::now()->subDay(),
            ]);
            TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'completed',
                'responded_at' => Carbon::now(),
            ]);

            // Act
            $data = $this->dashboardService->getDashboardData($this->dealership->id);

            // Assert
            expect($data['overdue_tasks'])->toBe(0);
        });

        it('counts completed tasks today', function () {
            // Arrange
            $task = Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'task_type' => 'individual',
            ]);
            TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'completed',
                'responded_at' => Carbon::now('UTC'),
            ]);

            // Act
            $data = $this->dashboardService->getDashboardData($this->dealership->id);

            // Assert
            expect($data['completed_tasks'])->toBe(1);
        });

        it('counts pending review tasks', function () {
            // Arrange
            $task = Task::factory()->create(['dealership_id' => $this->dealership->id]);
            TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);

            // Act
            $data = $this->dashboardService->getDashboardData($this->dealership->id);

            // Assert
            expect($data['pending_review_count'])->toBe(1);
        });

        it('counts open shifts', function () {
            // Arrange
            Shift::factory()->create([
                'user_id' => $this->employee->id,
                'dealership_id' => $this->dealership->id,
                'status' => 'open',
                'shift_end' => null,
            ]);
            Shift::factory()->create([
                'user_id' => $this->employee->id,
                'dealership_id' => $this->dealership->id,
                'status' => 'closed',
                'shift_end' => Carbon::now(),
            ]);

            // Act
            $data = $this->dashboardService->getDashboardData($this->dealership->id);

            // Assert
            expect($data['open_shifts'])->toBe(1);
        });

        it('counts late shifts today', function () {
            // Arrange - нужны разные пользователи из-за уникального ограничения
            $employee2 = User::factory()->create([
                'role' => Role::EMPLOYEE->value,
                'dealership_id' => $this->dealership->id
            ]);

            // Используем UTC время, чтобы попасть в границы "сегодня"
            $nowUtc = Carbon::now('UTC');

            Shift::factory()->create([
                'user_id' => $this->employee->id,
                'dealership_id' => $this->dealership->id,
                'shift_start' => $nowUtc,
                'late_minutes' => 15,
            ]);
            Shift::factory()->create([
                'user_id' => $employee2->id,
                'dealership_id' => $this->dealership->id,
                'shift_start' => $nowUtc,
                'late_minutes' => 0,
            ]);

            // Act
            $data = $this->dashboardService->getDashboardData($this->dealership->id);

            // Assert
            expect($data['late_shifts_today'])->toBe(1);
        });

        it('returns today tasks list', function () {
            // Arrange - tasks are created but without completed responses,
            // so they won't appear in today_tasks_list unless overdue
            Task::factory(10)->create(['dealership_id' => $this->dealership->id]);

            // Act
            $data = $this->dashboardService->getDashboardData($this->dealership->id);

            // Assert
            expect($data)->toHaveKey('today_tasks_list');
        });

        it('counts generator statistics', function () {
            // Arrange
            $generator = TaskGenerator::factory()->create([
                'dealership_id' => $this->dealership->id,
                'is_active' => true,
            ]);
            TaskGenerator::factory()->create([
                'dealership_id' => $this->dealership->id,
                'is_active' => false,
            ]);

            // Используем UTC время, чтобы попасть в границы "сегодня"
            $nowUtc = Carbon::now('UTC');

            Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'generator_id' => $generator->id,
                'created_at' => $nowUtc,
            ]);

            // Act
            $data = $this->dashboardService->getDashboardData($this->dealership->id);

            // Assert
            expect($data['total_generators'])->toBe(2);
            expect($data['active_generators'])->toBe(1);
            expect($data['tasks_generated_today'])->toBe(1);
        });

        it('filters data by dealership', function () {
            // Arrange
            $otherDealership = AutoDealership::factory()->create();

            Task::factory(3)->create(['dealership_id' => $this->dealership->id]);
            Task::factory(5)->create(['dealership_id' => $otherDealership->id]);

            // Act
            $data = $this->dashboardService->getDashboardData($this->dealership->id);

            // Assert
            expect($data['total_tasks'])->toBe(3);
        });

        it('returns timestamp', function () {
            // Act
            $data = $this->dashboardService->getDashboardData();

            // Assert
            expect($data['timestamp'])->toBeString();
            expect(Carbon::parse($data['timestamp']))->toBeInstanceOf(Carbon::class);
        });
    });

    describe('getOverdueTasksList', function () {
        it('returns overdue tasks with details', function () {
            // Arrange
            Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'is_active' => true,
                'deadline' => Carbon::now()->subHours(2),
                'title' => 'Просроченная задача',
            ]);

            // Act
            $data = $this->dashboardService->getDashboardData($this->dealership->id);

            // Assert
            expect($data['overdue_tasks_list'])->toHaveCount(1);
            expect($data['overdue_tasks_list'][0]['title'])->toBe('Просроченная задача');
        });

        it('limits overdue list to 10 items', function () {
            // Arrange
            Task::factory(15)->create([
                'dealership_id' => $this->dealership->id,
                'is_active' => true,
                'deadline' => Carbon::now()->subDay(),
            ]);

            // Act
            $data = $this->dashboardService->getDashboardData($this->dealership->id);

            // Assert
            expect($data['overdue_tasks_list'])->toHaveCount(10);
        });
    });

    describe('getPendingReviewTasks', function () {
        it('returns pending review tasks with proofs', function () {
            // Arrange
            $task = Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'title' => 'Задача на проверке',
            ]);
            TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);

            // Act
            $data = $this->dashboardService->getDashboardData($this->dealership->id);

            // Assert
            expect($data['pending_review_tasks'])->toHaveCount(1);
            expect($data['pending_review_tasks'][0]['title'])->toBe('Задача на проверке');
        });
    });

    describe('getActiveShifts', function () {
        it('returns active shifts with user info', function () {
            // Arrange
            $shift = Shift::factory()->create([
                'user_id' => $this->employee->id,
                'dealership_id' => $this->dealership->id,
                'status' => 'open',
                'shift_end' => null,
            ]);

            // Act
            $data = $this->dashboardService->getDashboardData($this->dealership->id);

            // Assert
            expect($data['active_shifts'])->toHaveCount(1);
            expect($data['active_shifts'][0]['user']['id'])->toBe($this->employee->id);
        });
    });
});
