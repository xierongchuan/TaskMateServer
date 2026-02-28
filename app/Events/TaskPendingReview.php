<?php

declare(strict_types=1);

namespace App\Events;

use App\Contracts\HasRabbitMQPayload;
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

        // Получаем менеджеров и владельцев автосалона
        $managerOwnerIds = User::where(function ($query) use ($task) {
            $query->where('dealership_id', $task->dealership_id)
                ->orWhereHas('dealerships', function ($q) use ($task) {
                    $q->where('auto_dealerships.id', $task->dealership_id);
                });
        })
            ->whereIn('role', ['manager', 'owner'])
            ->where('id', '!=', $this->response->user_id)
            ->pluck('id')
            ->toArray();

        if (empty($managerOwnerIds)) {
            return null;
        }

        return [
            'event' => 'task.pending_review',
            'task' => TaskAssigned::serializeTask($task),
            'user_ids' => array_values($managerOwnerIds),
            'submitted_by' => $this->response->user->full_name ?? 'Сотрудник',
            'response_id' => $this->response->id,
            'timestamp' => now()->toIso8601ZuluString(),
        ];
    }
}
