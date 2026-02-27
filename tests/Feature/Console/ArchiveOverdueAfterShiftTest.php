<?php

declare(strict_types=1);

use App\Models\Task;
use App\Models\Shift;
use App\Models\Setting;
use App\Models\AutoDealership;
use App\Models\User;
use App\Enums\Role;
use Carbon\Carbon;

describe('ArchiveOverdueAfterShift Command', function () {
    beforeEach(function () {
        $this->dealership = AutoDealership::factory()->create();
        $this->employee = User::factory()->create([
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $this->dealership->id
        ]);
    });

    it('command runs successfully', function () {
        // Act
        $this->artisan('tasks:archive-overdue-after-shift')
            ->assertExitCode(0);
    });

    it('archives overdue tasks with force flag', function () {
        // Arrange
        $shift = Shift::factory()->create([
            'user_id' => $this->employee->id,
            'dealership_id' => $this->dealership->id,
            'shift_start' => Carbon::now()->subHours(10),
            'shift_end' => Carbon::now()->subHours(2),
            'archived_tasks_processed' => false,
        ]);

        $task = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'is_active' => true,
            'archived_at' => null,
            'deadline' => Carbon::now()->subHours(5), // Deadline during the shift
        ]);

        // Act
        $this->artisan('tasks:archive-overdue-after-shift --force')
            ->assertExitCode(0);

        // Assert
        $task->refresh();
        expect($task->is_active)->toBeFalse();
        expect($task->archived_at)->not->toBeNull();
        expect($task->archive_reason)->toBe('expired_after_shift');
    });

    it('does not archive completed tasks', function () {
        // Arrange
        $shift = Shift::factory()->create([
            'user_id' => $this->employee->id,
            'dealership_id' => $this->dealership->id,
            'shift_start' => Carbon::now()->subHours(10),
            'shift_end' => Carbon::now()->subHours(2),
            'archived_tasks_processed' => false,
        ]);

        $task = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'is_active' => true,
            'archived_at' => null,
            'deadline' => Carbon::now()->subHours(5),
        ]);

        // Mark as completed
        \App\Models\TaskResponse::create([
            'task_id' => $task->id,
            'user_id' => $this->employee->id,
            'status' => 'completed',
            'responded_at' => Carbon::now(),
        ]);

        // Act
        $this->artisan('tasks:archive-overdue-after-shift --force')
            ->assertExitCode(0);

        // Assert - should not be archived
        $task->refresh();
        expect($task->is_active)->toBeTrue();
        expect($task->archived_at)->toBeNull();
    });

    it('respects dry-run flag', function () {
        // Arrange
        $shift = Shift::factory()->create([
            'user_id' => $this->employee->id,
            'dealership_id' => $this->dealership->id,
            'shift_start' => Carbon::now()->subHours(10),
            'shift_end' => Carbon::now()->subHours(2),
            'archived_tasks_processed' => false,
        ]);

        $task = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'is_active' => true,
            'archived_at' => null,
            'deadline' => Carbon::now()->subHours(5),
        ]);

        // Act
        $this->artisan('tasks:archive-overdue-after-shift --force --dry-run')
            ->assertExitCode(0);

        // Assert - should NOT be archived due to dry-run
        $task->refresh();
        expect($task->is_active)->toBeTrue();
        expect($task->archived_at)->toBeNull();
    });

    it('waits for configured hours after shift close', function () {
        // Arrange
        Setting::factory()->create([
            'dealership_id' => $this->dealership->id,
            'key' => 'archive_overdue_hours_after_shift',
            'value' => '5', // Wait 5 hours
        ]);

        $shift = Shift::factory()->create([
            'user_id' => $this->employee->id,
            'dealership_id' => $this->dealership->id,
            'shift_start' => Carbon::now()->subHours(10),
            'shift_end' => Carbon::now()->subHours(2), // Closed 2 hours ago, but we need to wait 5
            'archived_tasks_processed' => false,
        ]);

        $task = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'is_active' => true,
            'archived_at' => null,
            'deadline' => Carbon::now()->subHours(5),
        ]);

        // Act - without --force, should wait
        $this->artisan('tasks:archive-overdue-after-shift')
            ->assertExitCode(0);

        // Assert - should NOT be archived yet (only 2 hours passed, need 5)
        $task->refresh();
        expect($task->is_active)->toBeTrue();
    });

    it('only archives tasks from the same dealership', function () {
        // Arrange
        $otherDealership = AutoDealership::factory()->create();

        $shift = Shift::factory()->create([
            'user_id' => $this->employee->id,
            'dealership_id' => $this->dealership->id,
            'shift_start' => Carbon::now()->subHours(10),
            'shift_end' => Carbon::now()->subHours(2),
            'archived_tasks_processed' => false,
        ]);

        $taskInDealership = Task::factory()->create([
            'dealership_id' => $this->dealership->id,
            'is_active' => true,
            'archived_at' => null,
            'deadline' => Carbon::now()->subHours(5),
        ]);

        $taskInOtherDealership = Task::factory()->create([
            'dealership_id' => $otherDealership->id,
            'is_active' => true,
            'archived_at' => null,
            'deadline' => Carbon::now()->subHours(5),
        ]);

        // Act
        $this->artisan('tasks:archive-overdue-after-shift --force')
            ->assertExitCode(0);

        // Assert
        $taskInDealership->refresh();
        $taskInOtherDealership->refresh();

        expect($taskInDealership->is_active)->toBeFalse();
        expect($taskInOtherDealership->is_active)->toBeTrue(); // Not touched
    });

    it('marks shift as processed', function () {
        // Arrange
        $shift = Shift::factory()->create([
            'user_id' => $this->employee->id,
            'dealership_id' => $this->dealership->id,
            'shift_start' => Carbon::now()->subHours(10),
            'shift_end' => Carbon::now()->subHours(2),
            'archived_tasks_processed' => false,
        ]);

        // Act
        $this->artisan('tasks:archive-overdue-after-shift --force')
            ->assertExitCode(0);

        // Assert
        $shift->refresh();
        expect($shift->archived_tasks_processed)->toBeTrue();
    });
});
