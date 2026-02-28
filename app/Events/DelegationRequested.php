<?php

declare(strict_types=1);

namespace App\Events;

use App\Contracts\HasRabbitMQPayload;
use App\Models\TaskDelegation;

class DelegationRequested implements HasRabbitMQPayload
{
    public function __construct(
        public readonly TaskDelegation $delegation,
    ) {}

    public function rabbitPayload(): ?array
    {
        $this->delegation->loadMissing(['task', 'fromUser']);

        return [
            'event' => 'task.delegation_requested',
            'task' => TaskAssigned::serializeTask($this->delegation->task),
            'user_ids' => [$this->delegation->to_user_id],
            'from_user' => $this->delegation->fromUser->full_name ?? 'Сотрудник',
            'reason' => $this->delegation->reason,
            'delegation_id' => $this->delegation->id,
            'timestamp' => now()->toIso8601ZuluString(),
        ];
    }
}
