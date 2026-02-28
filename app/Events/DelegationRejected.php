<?php

declare(strict_types=1);

namespace App\Events;

use App\Contracts\HasRabbitMQPayload;
use App\Helpers\TimeHelper;
use App\Models\TaskDelegation;

class DelegationRejected implements HasRabbitMQPayload
{
    public function __construct(
        public readonly TaskDelegation $delegation,
    ) {}

    public function rabbitPayload(): ?array
    {
        $this->delegation->loadMissing(['task', 'toUser']);

        return [
            'event' => 'task.delegation_rejected',
            'task' => TaskAssigned::serializeTask($this->delegation->task),
            'user_ids' => [$this->delegation->from_user_id],
            'to_user' => $this->delegation->toUser->full_name ?? 'Сотрудник',
            'reason' => $this->delegation->rejection_reason,
            'delegation_id' => $this->delegation->id,
            'timestamp' => TimeHelper::toIsoZulu(TimeHelper::nowUtc()),
        ];
    }
}
