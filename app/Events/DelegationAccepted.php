<?php

declare(strict_types=1);

namespace App\Events;

use App\Contracts\HasRabbitMQPayload;
use App\Helpers\TimeHelper;
use App\Models\TaskDelegation;
use App\Models\User;

class DelegationAccepted implements HasRabbitMQPayload
{
    public function __construct(
        public readonly TaskDelegation $delegation,
    ) {}

    public function rabbitPayload(): ?array
    {
        $this->delegation->loadMissing(['task', 'fromUser', 'toUser']);
        $task = $this->delegation->task;

        // Инициатор + менеджеры/владельцы автосалона
        $userIds = [$this->delegation->from_user_id];

        if ($task->dealership_id) {
            $managerOwnerIds = User::managerOwnerIdsForDealership($task->dealership_id);
            $userIds = array_unique(array_merge($userIds, $managerOwnerIds));
        }

        return [
            'event' => 'task.delegation_accepted',
            'task' => TaskAssigned::serializeTask($task),
            'user_ids' => array_values($userIds),
            'from_user' => $this->delegation->fromUser->full_name ?? 'Сотрудник',
            'to_user' => $this->delegation->toUser->full_name ?? 'Сотрудник',
            'delegation_id' => $this->delegation->id,
            'timestamp' => TimeHelper::toIsoZulu(TimeHelper::nowUtc()),
        ];
    }
}
