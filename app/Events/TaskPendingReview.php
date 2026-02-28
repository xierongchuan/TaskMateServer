<?php

declare(strict_types=1);

namespace App\Events;

use App\Contracts\HasRabbitMQPayload;
use App\Helpers\TimeHelper;
use App\Models\TaskResponse;
use App\Models\User;

class TaskPendingReview implements HasRabbitMQPayload
{
    public function __construct(
        public readonly TaskResponse $response,
    ) {}

    public function rabbitPayload(): ?array
    {
        $this->response->loadMissing(['task', 'user']);
        $task = $this->response->task;

        // Получаем менеджеров и владельцев автосалона, исключая автора ответа
        $managerOwnerIds = array_values(array_diff(
            User::managerOwnerIdsForDealership($task->dealership_id),
            [$this->response->user_id],
        ));

        if (empty($managerOwnerIds)) {
            return null;
        }

        return [
            'event' => 'task.pending_review',
            'task' => TaskAssigned::serializeTask($task),
            'user_ids' => array_values($managerOwnerIds),
            'submitted_by' => $this->response->user->full_name ?? 'Сотрудник',
            'response_id' => $this->response->id,
            'timestamp' => TimeHelper::toIsoZulu(TimeHelper::nowUtc()),
        ];
    }
}
