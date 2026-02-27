<?php

declare(strict_types=1);

use App\Models\TaskNotification;
use App\Models\Task;
use App\Models\User;

describe('TaskNotification Model', function () {
    it('belongs to task', function () {
        $task = Task::factory()->create();
        $notification = TaskNotification::create([
            'task_id' => $task->id,
            'user_id' => User::factory()->create()->id,
            'notification_type' => 'overdue',
        ]);

        expect($notification->task->id)->toBe($task->id);
    });

    it('belongs to user', function () {
        $user = User::factory()->create();
        $notification = TaskNotification::create([
            'task_id' => Task::factory()->create()->id,
            'user_id' => $user->id,
            'notification_type' => 'assigned',
        ]);

        expect($notification->user->id)->toBe($user->id);
    });
});
