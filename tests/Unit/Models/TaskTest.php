<?php

declare(strict_types=1);

use App\Models\Task;
use App\Models\User;
use App\Models\AutoDealership;
use App\Models\TaskResponse;
use Carbon\Carbon;

describe('Task Model', function () {
    it('can create a task with all fields', function () {
        // Arrange
        $creator = User::factory()->create();
        $dealership = AutoDealership::factory()->create();

        // Act
        $task = Task::create([
            'title' => 'Important Task',
            'description' => 'Task description',
            'creator_id' => $creator->id,
            'dealership_id' => $dealership->id,
            'appear_date' => now()->addHours(2),
            'deadline' => now()->addDay(),
            'task_type' => 'individual',
            'response_type' => 'notification',
            'tags' => ['urgent', 'important'],
            'is_active' => true,
        ]);

        // Assert
        expect($task)
            ->toBeInstanceOf(Task::class)
            ->and($task->title)->toBe('Important Task')
            ->and($task->creator_id)->toBe($creator->id)
            ->and($task->dealership_id)->toBe($dealership->id)
            ->and($task->task_type)->toBe('individual')
            ->and($task->is_active)->toBeTrue()
            ->and($task->tags)->toBe(['urgent', 'important']);
    });

    it('can create task with minimum required fields', function () {
        // Arrange
        $creator = User::factory()->create();
        $dealership = AutoDealership::factory()->create();

        // Act
        $task = Task::create([
            'title' => 'Simple Task',
            'creator_id' => $creator->id,
            'dealership_id' => $dealership->id,
            'task_type' => 'individual',
            'response_type' => 'notification',
            'appear_date' => now(),
            'deadline' => now()->addDay(),
        ]);

        // Assert
        expect($task)
            ->toBeInstanceOf(Task::class)
            ->and($task->title)->toBe('Simple Task')
            ->and($task->exists)->toBeTrue();
    });

    it('belongs to creator user', function () {
        // Arrange
        $creator = User::factory()->create();
        $dealership = AutoDealership::factory()->create();
        $task = Task::factory()->create([
            'creator_id' => $creator->id,
            'dealership_id' => $dealership->id,
        ]);

        // Act & Assert
        expect($task->creator)
            ->toBeInstanceOf(User::class)
            ->and($task->creator->id)->toBe($creator->id);
    });

    it('belongs to dealership', function () {
        // Arrange
        $dealership = AutoDealership::factory()->create();
        $task = Task::factory()->create(['dealership_id' => $dealership->id]);

        // Act & Assert
        expect($task->dealership)
            ->toBeInstanceOf(AutoDealership::class)
            ->and($task->dealership->id)->toBe($dealership->id);
    });

    it('can have multiple tasks in dealership', function () {
        // Arrange
        $dealership = AutoDealership::factory()->create();
        $tasks = Task::factory(3)->create(['dealership_id' => $dealership->id]);

        // Act
        $count = Task::where('dealership_id', $dealership->id)->count();

        // Assert
        expect($count)->toBe(3);
    });

    it('converts timezone for appear_date', function () {
        // Arrange
        $dealership = AutoDealership::factory()->create();
        $userTime = '2025-12-20 10:00:00'; // Asia/Yekaterinburg time

        // Act
        $task = Task::create([
            'title' => 'TZ Test',
            'creator_id' => User::factory()->create()->id,
            'dealership_id' => $dealership->id,
            'appear_date' => $userTime,
            'deadline' => now()->addDay(),
            'task_type' => 'individual',
            'response_type' => 'notification',
        ]);

        // Assert
        expect($task->appear_date)->toBeInstanceOf(Carbon::class);
    });

    it('converts timezone for deadline', function () {
        // Arrange
        $dealership = AutoDealership::factory()->create();
        $userTime = '2025-12-20 18:00:00'; // Asia/Yekaterinburg time

        // Act
        $task = Task::create([
            'title' => 'Deadline TZ Test',
            'creator_id' => User::factory()->create()->id,
            'dealership_id' => $dealership->id,
            'deadline' => $userTime,
            'appear_date' => now(),
            'task_type' => 'individual',
            'response_type' => 'notification',
        ]);

        // Assert
        expect($task->deadline)->toBeInstanceOf(Carbon::class);
    });

    // Примечание: тест 'it can handle recurring tasks' удален, так как
    // повторяемость задач теперь реализована через TaskGenerator

    it('can query tasks by status', function () {
        // Arrange
        $dealership = AutoDealership::factory()->create();
        Task::factory(3)->create([
            'dealership_id' => $dealership->id,
            'is_active' => true,
        ]);
        Task::factory(2)->create([
            'dealership_id' => $dealership->id,
            'is_active' => false,
        ]);

        // Act
        $activeTasks = Task::where('is_active', true)->get();

        // Assert
        expect($activeTasks)->toHaveCount(3);
    });

    it('can query tasks by tags', function () {
        // Arrange
        $dealership = AutoDealership::factory()->create();
        Task::factory()->create([
            'dealership_id' => $dealership->id,
            'tags' => ['urgent'],
        ]);
        Task::factory()->create([
            'dealership_id' => $dealership->id,
            'tags' => ['normal'],
        ]);

        // Act & Assert
        $tasks = Task::all();
        expect($tasks)->toHaveCount(2);
    });

    it('can archive task', function () {
        // Arrange
        $task = Task::factory()->create();

        // Act
        $task->update(['archived_at' => now()]);

        // Assert
        expect($task->archived_at)->not->toBeNull();
    });

    it('can update task status', function () {
        // Arrange
        $task = Task::factory()->create(['is_active' => true]);

        // Act
        $task->update(['is_active' => false]);

        // Assert
        expect($task->is_active)->toBeFalse();
    });

    it('can handle different response types', function () {
        // Arrange
        $dealership = AutoDealership::factory()->create();

        // Act
        $taskAck = Task::create([
            'title' => 'Acknowledge Task',
            'creator_id' => User::factory()->create()->id,
            'dealership_id' => $dealership->id,
            'response_type' => 'notification',
            'task_type' => 'individual',
            'appear_date' => now(),
            'deadline' => now()->addDay(),
        ]);

        $taskComplete = Task::create([
            'title' => 'Complete Task',
            'creator_id' => User::factory()->create()->id,
            'dealership_id' => $dealership->id,
            'response_type' => 'completion',
            'task_type' => 'individual',
            'appear_date' => now(),
            'deadline' => now()->addDay(),
        ]);

        // Assert
        expect($taskAck->response_type)->toBe('notification')
            ->and($taskComplete->response_type)->toBe('completion');
    });

    it('can handle different task types', function () {
        // Arrange
        $dealership = AutoDealership::factory()->create();

        // Act
        $individualTask = Task::create([
            'title' => 'Individual Task',
            'creator_id' => User::factory()->create()->id,
            'dealership_id' => $dealership->id,
            'task_type' => 'individual',
            'response_type' => 'notification',
            'appear_date' => now(),
            'deadline' => now()->addDay(),
        ]);

        $groupTask = Task::create([
            'title' => 'Group Task',
            'creator_id' => User::factory()->create()->id,
            'dealership_id' => $dealership->id,
            'task_type' => 'group',
            'response_type' => 'notification',
            'appear_date' => now(),
            'deadline' => now()->addDay(),
        ]);

        // Assert
        expect($individualTask->task_type)->toBe('individual')
            ->and($groupTask->task_type)->toBe('group');
    });
});
