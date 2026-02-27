<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\AutoDealership;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskResponse;
use App\Models\User;
use Carbon\Carbon;

describe('Task Completion Percentage', function () {
    beforeEach(function () {
        $this->dealership = AutoDealership::factory()->create();
        $this->creator = User::factory()->create([
            'role' => Role::MANAGER->value,
            'dealership_id' => $this->dealership->id,
        ]);
    });

    describe('Individual Tasks', function () {
        it('returns 100% when completed', function () {
            $task = Task::factory()->individual()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->creator->id,
            ]);
            $employee = User::factory()->create([
                'role' => Role::EMPLOYEE->value,
                'dealership_id' => $this->dealership->id,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $employee->id]);
            TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $employee->id,
                'status' => 'completed',
                'responded_at' => Carbon::now(),
            ]);

            $task->load('responses', 'assignments');
            expect($task->completion_percentage)->toBe(100);
        });

        it('returns 0% when not completed', function () {
            $task = Task::factory()->individual()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->creator->id,
            ]);
            $employee = User::factory()->create([
                'role' => Role::EMPLOYEE->value,
                'dealership_id' => $this->dealership->id,
            ]);
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $employee->id]);

            $task->load('responses', 'assignments');
            expect($task->completion_percentage)->toBe(0);
        });
    });

    describe('Group Tasks', function () {
        it('returns 0% when no assignments', function () {
            $task = Task::factory()->group()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->creator->id,
            ]);
            // No assignments - edge case

            $task->load('responses', 'assignments');
            expect($task->completion_percentage)->toBe(0);
            // Важно: нет division by zero!
        });

        it('returns 0% when no completions', function () {
            $task = Task::factory()->group()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->creator->id,
            ]);
            $employees = User::factory()->count(3)->create([
                'role' => Role::EMPLOYEE->value,
                'dealership_id' => $this->dealership->id,
            ]);
            foreach ($employees as $employee) {
                TaskAssignment::create(['task_id' => $task->id, 'user_id' => $employee->id]);
            }

            $task->load('responses', 'assignments');
            expect($task->completion_percentage)->toBe(0);
        });

        it('returns 33% when 1 of 3 completed', function () {
            $task = Task::factory()->group()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->creator->id,
            ]);
            $employees = User::factory()->count(3)->create([
                'role' => Role::EMPLOYEE->value,
                'dealership_id' => $this->dealership->id,
            ]);
            foreach ($employees as $employee) {
                TaskAssignment::create(['task_id' => $task->id, 'user_id' => $employee->id]);
            }
            TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $employees[0]->id,
                'status' => 'completed',
                'responded_at' => Carbon::now(),
            ]);

            $task->load('responses', 'assignments');
            expect($task->completion_percentage)->toBe(33); // round(1/3 * 100)
        });

        it('returns 67% when 2 of 3 completed', function () {
            $task = Task::factory()->group()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->creator->id,
            ]);
            $employees = User::factory()->count(3)->create([
                'role' => Role::EMPLOYEE->value,
                'dealership_id' => $this->dealership->id,
            ]);
            foreach ($employees as $employee) {
                TaskAssignment::create(['task_id' => $task->id, 'user_id' => $employee->id]);
            }
            TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $employees[0]->id,
                'status' => 'completed',
                'responded_at' => Carbon::now(),
            ]);
            TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $employees[1]->id,
                'status' => 'completed',
                'responded_at' => Carbon::now(),
            ]);

            $task->load('responses', 'assignments');
            expect($task->completion_percentage)->toBe(67); // round(2/3 * 100)
        });

        it('returns 100% when all completed', function () {
            $task = Task::factory()->group()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->creator->id,
            ]);
            $employees = User::factory()->count(3)->create([
                'role' => Role::EMPLOYEE->value,
                'dealership_id' => $this->dealership->id,
            ]);
            foreach ($employees as $employee) {
                TaskAssignment::create(['task_id' => $task->id, 'user_id' => $employee->id]);
            }
            foreach ($employees as $employee) {
                TaskResponse::create([
                    'task_id' => $task->id,
                    'user_id' => $employee->id,
                    'status' => 'completed',
                    'responded_at' => Carbon::now(),
                ]);
            }

            $task->load('responses', 'assignments');
            expect($task->completion_percentage)->toBe(100);
        });

        it('returns 50% when 1 of 2 completed', function () {
            $task = Task::factory()->group()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->creator->id,
            ]);
            $employees = User::factory()->count(2)->create([
                'role' => Role::EMPLOYEE->value,
                'dealership_id' => $this->dealership->id,
            ]);
            foreach ($employees as $employee) {
                TaskAssignment::create(['task_id' => $task->id, 'user_id' => $employee->id]);
            }
            TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $employees[0]->id,
                'status' => 'completed',
                'responded_at' => Carbon::now(),
            ]);

            $task->load('responses', 'assignments');
            expect($task->completion_percentage)->toBe(50);
        });

        it('counts only completed status, not pending_review', function () {
            $task = Task::factory()->group()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->creator->id,
            ]);
            $employees = User::factory()->count(2)->create([
                'role' => Role::EMPLOYEE->value,
                'dealership_id' => $this->dealership->id,
            ]);
            foreach ($employees as $employee) {
                TaskAssignment::create(['task_id' => $task->id, 'user_id' => $employee->id]);
            }
            // 1 completed, 1 pending_review
            TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $employees[0]->id,
                'status' => 'completed',
                'responded_at' => Carbon::now(),
            ]);
            TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $employees[1]->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);

            $task->load('responses', 'assignments');
            expect($task->completion_percentage)->toBe(50); // Only completed counts
        });

        it('counts unique users only once', function () {
            $task = Task::factory()->group()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->creator->id,
            ]);
            $employees = User::factory()->count(2)->create([
                'role' => Role::EMPLOYEE->value,
                'dealership_id' => $this->dealership->id,
            ]);
            foreach ($employees as $employee) {
                TaskAssignment::create(['task_id' => $task->id, 'user_id' => $employee->id]);
            }
            // 2 responses from same user (edge case - resubmission)
            TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $employees[0]->id,
                'status' => 'completed',
                'responded_at' => Carbon::now(),
            ]);

            $task->load('responses', 'assignments');
            // Only 1 unique user completed, so 50%
            expect($task->completion_percentage)->toBe(50);
        });
    });
});
