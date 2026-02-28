<?php

declare(strict_types=1);

namespace App\Events;

use App\Contracts\HasRabbitMQPayload;
use App\Models\TaskResponse;

class TaskApproved implements HasRabbitMQPayload
{
    public function __construct(
        public readonly TaskResponse $response,
    ) {}

    public function rabbitPayload(): ?array
    {
        $this->response->loadMissing(['task', 'user']);

        return [
            'event' => 'task.approved',
            'task' => TaskAssigned::serializeTask($this->response->task),
            'user_ids' => [$this->response->user_id],
            'timestamp' => now()->toIso8601ZuluString(),
        ];
    }
}
