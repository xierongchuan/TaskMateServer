<?php

declare(strict_types=1);

namespace App\Events;

use App\Contracts\HasRabbitMQPayload;
use App\Helpers\TimeHelper;
use App\Models\TaskResponse;

class TaskRejected implements HasRabbitMQPayload
{
    public function __construct(
        public readonly TaskResponse $response,
        public readonly string $reason,
    ) {}

    public function rabbitPayload(): ?array
    {
        $this->response->loadMissing(['task', 'user']);

        return [
            'event' => 'task.rejected',
            'task' => TaskAssigned::serializeTask($this->response->task),
            'user_ids' => [$this->response->user_id],
            'reason' => $this->reason,
            'timestamp' => TimeHelper::toIsoZulu(TimeHelper::nowUtc()),
        ];
    }
}
