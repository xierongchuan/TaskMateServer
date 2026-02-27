<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\AutoDealership;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskResponse;
use App\Models\User;
use Carbon\Carbon;

describe('Task Status Accessor', function () {
    beforeEach(function () {
        $this->dealership = AutoDealership::factory()->create();
        $this->creator = User::factory()->create([
            'role' => Role::MANAGER->value,
            'dealership_id' => $this->dealership->id,
        ]);
        $this->employee = User::factory()->create([
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $this->dealership->id,
        ]);
    });

    afterEach(function () {
        Carbon::setTestNow();
    });

    describe('Individual Task Status', function () {
        it('returns pending when no responses', function () {
            $task = Task::factory()->individual()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->creator->id,
                'deadline' => Carbon::now()->addDay(),
                'is_active' => true,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);

            $task->load('responses', 'assignments');
            expect($task->status)->toBe('pending');
        });

        it('returns acknowledged when response is acknowledged', function () {
            $task = Task::factory()->individual()->notification()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->creator->id,
                'deadline' => Carbon::now()->addDay(),
                'is_active' => true,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);
            TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'acknowledged',
                'responded_at' => Carbon::now(),
            ]);

            $task->load('responses', 'assignments');
            expect($task->status)->toBe('acknowledged');
        });

        it('returns pending_review when response is pending_review', function () {
            $task = Task::factory()->individual()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->creator->id,
                'deadline' => Carbon::now()->addDay(),
                'is_active' => true,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);
            TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);

            $task->load('responses', 'assignments');
            expect($task->status)->toBe('pending_review');
        });

        it('returns completed when response is completed before deadline', function () {
            $deadline = Carbon::now()->addDay();
            $task = Task::factory()->individual()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->creator->id,
                'deadline' => $deadline,
                'is_active' => true,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);
            TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'completed',
                'responded_at' => Carbon::now(), // Before deadline
            ]);

            $task->load('responses', 'assignments');
            expect($task->status)->toBe('completed');
        });

        it('returns completed_late when response is completed after deadline', function () {
            $deadline = Carbon::now()->subHour();
            $task = Task::factory()->individual()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->creator->id,
                'deadline' => $deadline,
                'is_active' => true,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);
            TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'completed',
                'responded_at' => Carbon::now(), // After deadline
            ]);

            $task->load('responses', 'assignments');
            expect($task->status)->toBe('completed_late');
        });

        it('returns overdue when deadline passed and not completed', function () {
            $task = Task::factory()->individual()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->creator->id,
                'deadline' => Carbon::now()->subHour(),
                'is_active' => true,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);

            $task->load('responses', 'assignments');
            expect($task->status)->toBe('overdue');
        });

        it('returns pending when inactive even if deadline passed', function () {
            $task = Task::factory()->individual()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->creator->id,
                'deadline' => Carbon::now()->subHour(),
                'is_active' => false, // Inactive!
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);

            $task->load('responses', 'assignments');
            expect($task->status)->toBe('pending'); // Not overdue because inactive
        });
    });

    describe('Group Task Status', function () {
        beforeEach(function () {
            $this->employees = User::factory()->count(3)->create([
                'role' => Role::EMPLOYEE->value,
                'dealership_id' => $this->dealership->id,
            ]);
        });

        it('returns pending when no responses in group task', function () {
            $task = Task::factory()->group()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->creator->id,
                'deadline' => Carbon::now()->addDay(),
                'is_active' => true,
            ]);
            foreach ($this->employees as $employee) {
                TaskAssignment::create(['task_id' => $task->id, 'user_id' => $employee->id]);
            }

            $task->load('responses', 'assignments');
            expect($task->status)->toBe('pending');
        });

        it('returns pending_review when some responses are pending_review', function () {
            $task = Task::factory()->group()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->creator->id,
                'deadline' => Carbon::now()->addDay(),
                'is_active' => true,
            ]);
            foreach ($this->employees as $employee) {
                TaskAssignment::create(['task_id' => $task->id, 'user_id' => $employee->id]);
            }
            // Only 1 employee submitted
            TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employees[0]->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);

            $task->load('responses', 'assignments');
            expect($task->status)->toBe('pending_review');
        });

        it('returns completed only when ALL assignees completed', function () {
            $task = Task::factory()->group()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->creator->id,
                'deadline' => Carbon::now()->addDay(),
                'is_active' => true,
            ]);
            foreach ($this->employees as $employee) {
                TaskAssignment::create(['task_id' => $task->id, 'user_id' => $employee->id]);
            }
            // Only 2 of 3 completed
            TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employees[0]->id,
                'status' => 'completed',
                'responded_at' => Carbon::now(),
            ]);
            TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employees[1]->id,
                'status' => 'completed',
                'responded_at' => Carbon::now(),
            ]);

            $task->load('responses', 'assignments');
            expect($task->status)->not->toBe('completed');
            // Should be pending (waiting for 3rd employee)
        });

        it('returns completed when all assignees completed before deadline', function () {
            $deadline = Carbon::now()->addDay();
            $task = Task::factory()->group()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->creator->id,
                'deadline' => $deadline,
                'is_active' => true,
            ]);
            foreach ($this->employees as $employee) {
                TaskAssignment::create(['task_id' => $task->id, 'user_id' => $employee->id]);
            }
            // All 3 completed
            foreach ($this->employees as $employee) {
                TaskResponse::create([
                    'task_id' => $task->id,
                    'user_id' => $employee->id,
                    'status' => 'completed',
                    'responded_at' => Carbon::now(),
                ]);
            }

            $task->load('responses', 'assignments');
            expect($task->status)->toBe('completed');
        });

        it('returns completed_late when any assignee completed after deadline', function () {
            $deadline = Carbon::now();
            $task = Task::factory()->group()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->creator->id,
                'deadline' => $deadline,
                'is_active' => true,
            ]);
            foreach ($this->employees as $employee) {
                TaskAssignment::create(['task_id' => $task->id, 'user_id' => $employee->id]);
            }
            // 2 completed before deadline
            TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employees[0]->id,
                'status' => 'completed',
                'responded_at' => $deadline->copy()->subMinute(),
            ]);
            TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employees[1]->id,
                'status' => 'completed',
                'responded_at' => $deadline->copy()->subMinute(),
            ]);
            // 1 completed after deadline
            TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employees[2]->id,
                'status' => 'completed',
                'responded_at' => $deadline->copy()->addHour(),
            ]);

            $task->load('responses', 'assignments');
            expect($task->status)->toBe('completed_late');
        });

        it('returns overdue when deadline passed and not all completed', function () {
            $task = Task::factory()->group()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->creator->id,
                'deadline' => Carbon::now()->subHour(),
                'is_active' => true,
            ]);
            foreach ($this->employees as $employee) {
                TaskAssignment::create(['task_id' => $task->id, 'user_id' => $employee->id]);
            }
            // Only 1 completed
            TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employees[0]->id,
                'status' => 'completed',
                'responded_at' => Carbon::now()->subMinutes(30),
            ]);

            $task->load('responses', 'assignments');
            expect($task->status)->toBe('overdue');
        });
    });

    describe('Edge Cases', function () {
        it('handles far future deadline correctly', function () {
            // Задача с очень далёким deadline не становится overdue
            $task = Task::factory()->individual()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->creator->id,
                'deadline' => Carbon::now()->addYears(10), // 10 лет вперёд
                'is_active' => true,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);

            // Даже через год - не overdue (deadline ещё не прошёл)
            Carbon::setTestNow(Carbon::now()->addYear());

            $task->load('responses', 'assignments');
            expect($task->status)->toBe('pending');
        });

        it('handles empty assignments correctly', function () {
            $task = Task::factory()->group()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->creator->id,
                'deadline' => Carbon::now()->addDay(),
                'is_active' => true,
            ]);
            // No assignments!

            $task->load('responses', 'assignments');
            expect($task->status)->toBe('pending');
        });

        it('ignores rejected responses when calculating status', function () {
            $task = Task::factory()->individual()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->creator->id,
                'deadline' => Carbon::now()->addDay(),
                'is_active' => true,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employee->id]);
            TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'rejected',
                'responded_at' => Carbon::now(),
            ]);

            $task->load('responses', 'assignments');
            // Rejected is not pending_review or completed, so task is still pending
            expect($task->status)->toBe('pending');
        });

        it('prioritizes pending_review over acknowledged', function () {
            $task = Task::factory()->group()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->creator->id,
                'deadline' => Carbon::now()->addDay(),
                'is_active' => true,
            ]);
            $employees = User::factory()->count(2)->create([
                'role' => Role::EMPLOYEE->value,
                'dealership_id' => $this->dealership->id,
            ]);
            foreach ($employees as $employee) {
                TaskAssignment::create(['task_id' => $task->id, 'user_id' => $employee->id]);
            }
            // 1 acknowledged, 1 pending_review
            TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $employees[0]->id,
                'status' => 'acknowledged',
                'responded_at' => Carbon::now(),
            ]);
            TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $employees[1]->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);

            $task->load('responses', 'assignments');
            expect($task->status)->toBe('pending_review');
        });
    });
});
