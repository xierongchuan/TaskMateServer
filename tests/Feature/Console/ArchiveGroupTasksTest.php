<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\AutoDealership;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskResponse;
use App\Models\User;
use Carbon\Carbon;

beforeEach(function () {
    $this->dealership = AutoDealership::factory()->create();

    $this->manager = User::factory()->create([
        'role' => Role::MANAGER,
        'dealership_id' => $this->dealership->id,
    ]);

    $this->employees = User::factory()->count(3)->create([
        'role' => Role::EMPLOYEE,
        'dealership_id' => $this->dealership->id,
    ]);
});

describe('ArchiveCompletedTasks - Group Task Handling', function () {
    it('does not archive group task when only some employees completed', function () {
        $task = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'is_active' => true,
            'archived_at' => null,
            'task_type' => 'group',
        ]);

        // Assign all 3 employees
        foreach ($this->employees as $employee) {
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $employee->id]);
        }

        // Only 2 out of 3 completed (2 days ago)
        $twoCompletedEmployees = $this->employees->take(2);
        foreach ($twoCompletedEmployees as $employee) {
            $response = new TaskResponse([
                'task_id' => $task->id,
                'user_id' => $employee->id,
                'status' => 'completed',
            ]);
            $response->created_at = Carbon::now()->subDays(2);
            $response->save();
        }

        // Verify task status is NOT completed
        $task->load(['responses', 'assignments']);
        expect($task->status)->toBe('pending'); // Not all completed

        $this->artisan('tasks:archive-completed', ['--force' => true, '--type' => 'completed'])
            ->assertSuccessful();

        $task->refresh();
        expect($task->archived_at)->toBeNull();
        expect($task->is_active)->toBeTrue();
    });

    it('archives group task when all employees completed', function () {
        $task = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'is_active' => true,
            'archived_at' => null,
            'task_type' => 'group',
        ]);

        // Assign all 3 employees
        foreach ($this->employees as $employee) {
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $employee->id]);
        }

        // ALL 3 employees completed (2 days ago)
        foreach ($this->employees as $employee) {
            $response = new TaskResponse([
                'task_id' => $task->id,
                'user_id' => $employee->id,
                'status' => 'completed',
            ]);
            $response->created_at = Carbon::now()->subDays(2);
            $response->save();
        }

        // Verify task status IS completed
        $task->load(['responses', 'assignments']);
        expect($task->status)->toBe('completed');

        Setting::create([
            'dealership_id' => $this->dealership->id,
            'key' => 'archive_completed_time',
            'value' => '03:00',
            'type' => 'time',
        ]);

        $this->artisan('tasks:archive-completed', ['--force' => true, '--type' => 'completed'])
            ->assertSuccessful();

        $task->refresh();
        expect($task->archived_at)->not->toBeNull();
        expect($task->is_active)->toBeFalse();
        expect($task->archive_reason)->toBe('completed');
    });

    it('does not archive group task when some responses are pending_review', function () {
        $task = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'is_active' => true,
            'archived_at' => null,
            'task_type' => 'group',
        ]);

        foreach ($this->employees as $employee) {
            TaskAssignment::create(['task_id' => $task->id, 'user_id' => $employee->id]);
        }

        // 2 completed, 1 pending_review
        $response1 = new TaskResponse([
            'task_id' => $task->id,
            'user_id' => $this->employees[0]->id,
            'status' => 'completed',
        ]);
        $response1->created_at = Carbon::now()->subDays(2);
        $response1->save();

        $response2 = new TaskResponse([
            'task_id' => $task->id,
            'user_id' => $this->employees[1]->id,
            'status' => 'completed',
        ]);
        $response2->created_at = Carbon::now()->subDays(2);
        $response2->save();

        TaskResponse::create([
            'task_id' => $task->id,
            'user_id' => $this->employees[2]->id,
            'status' => 'pending_review',
        ]);

        $task->load(['responses', 'assignments']);
        expect($task->status)->toBe('pending_review');

        $this->artisan('tasks:archive-completed', ['--force' => true, '--type' => 'completed'])
            ->assertSuccessful();

        $task->refresh();
        expect($task->archived_at)->toBeNull();
    });

    it('archives individual task with single completed response', function () {
        $task = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'is_active' => true,
            'archived_at' => null,
            'task_type' => 'individual',
        ]);

        TaskAssignment::create(['task_id' => $task->id, 'user_id' => $this->employees[0]->id]);

        $response = new TaskResponse([
            'task_id' => $task->id,
            'user_id' => $this->employees[0]->id,
            'status' => 'completed',
        ]);
        $response->created_at = Carbon::now()->subDays(2);
        $response->save();

        $task->load(['responses', 'assignments']);
        expect($task->status)->toBe('completed');

        Setting::create([
            'dealership_id' => $this->dealership->id,
            'key' => 'archive_completed_time',
            'value' => '03:00',
            'type' => 'time',
        ]);

        $this->artisan('tasks:archive-completed', ['--force' => true, '--type' => 'completed'])
            ->assertSuccessful();

        $task->refresh();
        expect($task->archived_at)->not->toBeNull();
        expect($task->archive_reason)->toBe('completed');
    });
});

describe('ArchiveOverdueAfterShift - completed_late fix', function () {
    it('correctly identifies completed tasks (does not use completed_late for TaskResponse)', function () {
        // This test verifies the fix: completed_late is a computed Task.status,
        // not a TaskResponse.status. The query should only look for 'completed'.

        $shift = \App\Models\Shift::factory()->create([
            'dealership_id' => $this->dealership->id,
            'user_id' => $this->employees[0]->id,
            'shift_start' => Carbon::now()->subHours(10),
            'shift_end' => Carbon::now()->subHours(3),
            'status' => 'closed',
            'archived_tasks_processed' => false,
        ]);

        // Task with deadline before shift end, but completed (response.status = 'completed')
        $completedTask = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'is_active' => true,
            'archived_at' => null,
            'task_type' => 'individual',
            'deadline' => Carbon::now()->subHours(5), // Before shift end
        ]);
        TaskAssignment::create(['task_id' => $completedTask->id, 'user_id' => $this->employees[0]->id]);
        TaskResponse::create([
            'task_id' => $completedTask->id,
            'user_id' => $this->employees[0]->id,
            'status' => 'completed', // This is the only valid completed status for TaskResponse
            'responded_at' => Carbon::now()->subHours(4), // Completed after deadline = completed_late as Task.status
        ]);

        // Task with deadline before shift end, NOT completed
        $overdueTask = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
            'is_active' => true,
            'archived_at' => null,
            'task_type' => 'individual',
            'deadline' => Carbon::now()->subHours(5),
        ]);
        TaskAssignment::create(['task_id' => $overdueTask->id, 'user_id' => $this->employees[0]->id]);
        // No response - truly overdue

        $this->artisan('tasks:archive-overdue-after-shift', ['--force' => true])
            ->assertSuccessful();

        // Completed task should NOT be archived (even though it was completed_late)
        $completedTask->refresh();
        expect($completedTask->archived_at)->toBeNull();
        expect($completedTask->is_active)->toBeTrue();

        // Overdue task SHOULD be archived
        $overdueTask->refresh();
        expect($overdueTask->archived_at)->not->toBeNull();
        expect($overdueTask->archive_reason)->toBe('expired_after_shift');
    });
});
