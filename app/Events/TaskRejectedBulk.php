<?php

declare(strict_types=1);

namespace App\Events;

use App\Contracts\HasRabbitMQPayload;
use App\Models\Task;

class TaskRejectedBulk implements HasRabbitMQPayload
{
    /**
     * @param  array<int>  $userIds  ID пользователей, чьи ответы отклонены
     */
    public function __construct(
        public readonly Task $task,
        public readonly array $userIds,
        public readonly string $reason,
    ) {}

    public function rabbitPayload(): ?array
    {
        return [
            'event' => 'task.rejected',
            'task' => TaskAssigned::serializeTask($this->task),
            'user_ids' => array_values($this->userIds),
            'reason' => $this->reason,
            'timestamp' => now()->toIso8601ZuluString(),
        ];
    }
}
