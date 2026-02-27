<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\AutoDealership;
use App\Models\Shift;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskResponse;
use App\Models\User;
use App\Services\EmployeeStatsService;
use Carbon\Carbon;

describe('EmployeeStatsService', function () {
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
        $this->service = new EmployeeStatsService;
        $this->from = Carbon::now('UTC')->subMonth();
        $this->to = Carbon::now('UTC');
    });

    describe('getStats', function () {
        it('возвращает структуру данных со всеми ключами', function () {
            // Act
            $stats = $this->service->getStats($this->employee, $this->from, $this->to);

            // Assert
            expect($stats)->toHaveKeys([
                'employee_id',
                'employee_name',
                'total_tasks',
                'completed_tasks',
                'completed_on_time',
                'completed_late',
                'completion_rate',
                'overdue_tasks',
                'pending_review',
                'rejected_tasks',
                'avg_completion_time_hours',
                'tasks_by_type',
                'total_shifts',
                'late_shifts',
                'avg_late_minutes',
                'missed_shifts',
                'performance_score',
                'has_history',
            ]);

            expect($stats['employee_id'])->toBe($this->employee->id);
            expect($stats['employee_name'])->toBe($this->employee->full_name);
        });

        it('возвращает нули при отсутствии данных', function () {
            // Act
            $stats = $this->service->getStats($this->employee, $this->from, $this->to);

            // Assert
            expect($stats['total_tasks'])->toBe(0);
            expect($stats['completed_tasks'])->toBe(0);
            expect($stats['completed_on_time'])->toBe(0);
            expect($stats['completed_late'])->toBe(0);
            expect($stats['completion_rate'])->toBe(0);
            expect($stats['overdue_tasks'])->toBe(0);
            expect($stats['pending_review'])->toBe(0);
            expect($stats['rejected_tasks'])->toBe(0);
            expect($stats['avg_completion_time_hours'])->toBe(0);
            expect($stats['total_shifts'])->toBe(0);
            expect($stats['late_shifts'])->toBe(0);
            expect($stats['avg_late_minutes'])->toBe(0);
            expect($stats['missed_shifts'])->toBe(0);
            expect($stats['performance_score'])->toBe(100);
            expect($stats['has_history'])->toBeFalse();
        });

        it('считает общее количество задач за период', function () {
            // Arrange
            $tasksInRange = Task::factory(3)->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
                'created_at' => Carbon::now('UTC')->subWeek(),
            ]);

            foreach ($tasksInRange as $task) {
                TaskAssignment::create([
                    'task_id' => $task->id,
                    'user_id' => $this->employee->id,
                ]);
            }

            // Задача вне диапазона
            $outsideTask = Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
                'created_at' => Carbon::now('UTC')->subMonths(3),
            ]);
            TaskAssignment::create([
                'task_id' => $outsideTask->id,
                'user_id' => $this->employee->id,
            ]);

            // Act
            $stats = $this->service->getStats($this->employee, $this->from, $this->to);

            // Assert
            expect($stats['total_tasks'])->toBe(3);
        });

        it('считает задачи выполненные вовремя', function () {
            // Arrange
            $task = Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
                'deadline' => Carbon::now('UTC')->addDay(),
                'created_at' => Carbon::now('UTC')->subWeek(),
            ]);
            TaskAssignment::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
            ]);
            TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'completed',
                'responded_at' => Carbon::now('UTC'),
            ]);

            // Act
            $stats = $this->service->getStats($this->employee, $this->from, $this->to);

            // Assert
            expect($stats['completed_on_time'])->toBe(1);
            expect($stats['completed_late'])->toBe(0);
            expect($stats['completed_tasks'])->toBe(1);
        });

        it('считает задачи выполненные с опозданием', function () {
            // Arrange
            $deadline = Carbon::now('UTC')->subDays(2);
            $task = Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
                'deadline' => $deadline,
                'created_at' => Carbon::now('UTC')->subWeek(),
            ]);
            TaskAssignment::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
            ]);
            TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'completed',
                'responded_at' => $deadline->copy()->addHour(),
            ]);

            // Act
            $stats = $this->service->getStats($this->employee, $this->from, $this->to);

            // Assert
            expect($stats['completed_late'])->toBe(1);
            expect($stats['completed_on_time'])->toBe(0);
        });

        it('считает просроченные задачи', function () {
            // Arrange
            $task = Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
                'deadline' => Carbon::now('UTC')->subDay(),
                'is_active' => true,
                'created_at' => Carbon::now('UTC')->subWeek(),
            ]);
            TaskAssignment::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
            ]);

            // Act
            $stats = $this->service->getStats($this->employee, $this->from, $this->to);

            // Assert
            expect($stats['overdue_tasks'])->toBe(1);
        });

        it('не считает неактивные задачи просроченными', function () {
            // Arrange
            $task = Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
                'deadline' => Carbon::now('UTC')->subDay(),
                'is_active' => false,
                'created_at' => Carbon::now('UTC')->subWeek(),
            ]);
            TaskAssignment::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
            ]);

            // Act
            $stats = $this->service->getStats($this->employee, $this->from, $this->to);

            // Assert
            expect($stats['overdue_tasks'])->toBe(0);
        });

        it('считает задачи на проверке', function () {
            // Arrange
            $task = Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
                'created_at' => Carbon::now('UTC')->subWeek(),
            ]);
            TaskAssignment::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
            ]);
            TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now('UTC'),
            ]);

            // Act
            $stats = $this->service->getStats($this->employee, $this->from, $this->to);

            // Assert
            expect($stats['pending_review'])->toBe(1);
        });

        it('считает отклонённые задачи', function () {
            // Arrange
            $task = Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
                'created_at' => Carbon::now('UTC')->subWeek(),
            ]);
            TaskAssignment::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
            ]);
            TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'rejected',
                'responded_at' => Carbon::now('UTC'),
            ]);

            // Act
            $stats = $this->service->getStats($this->employee, $this->from, $this->to);

            // Assert
            expect($stats['rejected_tasks'])->toBe(1);
        });

        it('группирует задачи по типам', function () {
            // Arrange
            $types = ['notification', 'notification', 'completion', 'completion_with_proof'];
            foreach ($types as $type) {
                $task = Task::factory()->create([
                    'dealership_id' => $this->dealership->id,
                    'creator_id' => $this->manager->id,
                    'response_type' => $type,
                    'created_at' => Carbon::now('UTC')->subWeek(),
                ]);
                TaskAssignment::create([
                    'task_id' => $task->id,
                    'user_id' => $this->employee->id,
                ]);
            }

            // Act
            $stats = $this->service->getStats($this->employee, $this->from, $this->to);

            // Assert
            expect($stats['tasks_by_type'])->toBe([
                'notification' => 2,
                'completion' => 1,
                'completion_with_proof' => 1,
            ]);
        });

        it('вычисляет процент выполнения', function () {
            // Arrange — 4 задачи, 2 выполнены
            for ($i = 0; $i < 4; $i++) {
                $task = Task::factory()->create([
                    'dealership_id' => $this->dealership->id,
                    'creator_id' => $this->manager->id,
                    'deadline' => Carbon::now('UTC')->addDay(),
                    'created_at' => Carbon::now('UTC')->subWeek(),
                ]);
                TaskAssignment::create([
                    'task_id' => $task->id,
                    'user_id' => $this->employee->id,
                ]);

                if ($i < 2) {
                    TaskResponse::create([
                        'task_id' => $task->id,
                        'user_id' => $this->employee->id,
                        'status' => 'completed',
                        'responded_at' => Carbon::now('UTC'),
                    ]);
                }
            }

            // Act
            $stats = $this->service->getStats($this->employee, $this->from, $this->to);

            // Assert
            expect($stats['completion_rate'])->toBe(50.0);
        });

        it('считает статистику смен', function () {
            // Arrange
            $nowUtc = Carbon::now('UTC');

            // Обычная смена (закрытая — partial unique index запрещает 2 open смены)
            Shift::factory()->closed()->create([
                'user_id' => $this->employee->id,
                'dealership_id' => $this->dealership->id,
                'shift_start' => $nowUtc->copy()->subDays(2),
                'late_minutes' => 0,
            ]);

            // Опоздавшая смена (открытая)
            Shift::factory()->create([
                'user_id' => $this->employee->id,
                'dealership_id' => $this->dealership->id,
                'shift_start' => $nowUtc->copy()->subDay(),
                'late_minutes' => 20,
            ]);

            // Act
            $stats = $this->service->getStats($this->employee, $this->from, $this->to);

            // Assert
            expect($stats['total_shifts'])->toBe(2);
            expect($stats['late_shifts'])->toBe(1);
            expect($stats['avg_late_minutes'])->toBe(20);
        });

        it('missed_shifts всегда 0 так как shift_start NOT NULL', function () {
            // shift_start NOT NULL в БД, поэтому whereNull('shift_start') всегда пуст
            // Act
            $stats = $this->service->getStats($this->employee, $this->from, $this->to);

            // Assert
            expect($stats['missed_shifts'])->toBe(0);
        });

        it('вычисляет рейтинг производительности', function () {
            // Arrange — 2 просроченные задачи + 1 опоздавшая смена
            // score = 100 - (2*5) - (1*10) = 80

            // Просроченные задачи
            for ($i = 0; $i < 2; $i++) {
                $task = Task::factory()->create([
                    'dealership_id' => $this->dealership->id,
                    'creator_id' => $this->manager->id,
                    'deadline' => Carbon::now('UTC')->subDay(),
                    'is_active' => true,
                    'created_at' => Carbon::now('UTC')->subWeek(),
                ]);
                TaskAssignment::create([
                    'task_id' => $task->id,
                    'user_id' => $this->employee->id,
                ]);
            }

            // Опоздавшая смена
            Shift::factory()->create([
                'user_id' => $this->employee->id,
                'dealership_id' => $this->dealership->id,
                'shift_start' => Carbon::now('UTC')->subDay(),
                'late_minutes' => 15,
            ]);

            // Act
            $stats = $this->service->getStats($this->employee, $this->from, $this->to);

            // Assert
            expect($stats['performance_score'])->toBe(80);
        });

        it('ограничивает рейтинг диапазоном 0-100', function () {
            // Arrange — много просроченных задач чтобы уйти ниже 0
            for ($i = 0; $i < 25; $i++) {
                $task = Task::factory()->create([
                    'dealership_id' => $this->dealership->id,
                    'creator_id' => $this->manager->id,
                    'deadline' => Carbon::now('UTC')->subDay(),
                    'is_active' => true,
                    'created_at' => Carbon::now('UTC')->subWeek(),
                ]);
                TaskAssignment::create([
                    'task_id' => $task->id,
                    'user_id' => $this->employee->id,
                ]);
            }

            // Act
            $stats = $this->service->getStats($this->employee, $this->from, $this->to);

            // Assert — score = 100 - (25*5) = -25 → clamped to 0
            expect($stats['performance_score'])->toBe(0);
        });

        it('возвращает has_history true при наличии задач', function () {
            // Arrange
            $task = Task::factory()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
                'created_at' => Carbon::now('UTC')->subWeek(),
            ]);
            TaskAssignment::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
            ]);

            // Act
            $stats = $this->service->getStats($this->employee, $this->from, $this->to);

            // Assert
            expect($stats['has_history'])->toBeTrue();
        });

        it('возвращает has_history true при наличии смен', function () {
            // Arrange
            Shift::factory()->create([
                'user_id' => $this->employee->id,
                'dealership_id' => $this->dealership->id,
                'shift_start' => Carbon::now('UTC')->subDay(),
            ]);

            // Act
            $stats = $this->service->getStats($this->employee, $this->from, $this->to);

            // Assert
            expect($stats['has_history'])->toBeTrue();
        });
    });
});
