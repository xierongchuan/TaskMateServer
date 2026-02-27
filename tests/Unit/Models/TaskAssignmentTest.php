<?php

declare(strict_types=1);

use App\Models\TaskAssignment;
use App\Models\Task;
use App\Models\User;

describe('TaskAssignment Model', function () {
    it('belongs to task', function () {
        $task = Task::factory()->create();
        $assignment = TaskAssignment::create([
            'task_id' => $task->id,
            'user_id' => User::factory()->create()->id,
        ]);

        expect($assignment->task->id)->toBe($task->id);
    });

    it('belongs to user', function () {
        $user = User::factory()->create();
        $assignment = TaskAssignment::create([
            'task_id' => Task::factory()->create()->id,
            'user_id' => $user->id,
        ]);

        expect($assignment->user->id)->toBe($user->id);
    });
});
