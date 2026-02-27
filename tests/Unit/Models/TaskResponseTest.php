<?php

declare(strict_types=1);

use App\Models\TaskResponse;
use App\Models\Task;
use App\Models\User;

describe('TaskResponse Model', function () {
    it('belongs to task', function () {
        $task = Task::factory()->create();
        $response = TaskResponse::factory()->create(['task_id' => $task->id]);

        expect($response->task->id)->toBe($task->id);
    });

    it('belongs to user', function () {
        $user = User::factory()->create();
        $response = TaskResponse::factory()->create(['user_id' => $user->id]);

        expect($response->user->id)->toBe($user->id);
    });
});
