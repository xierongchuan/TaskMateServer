<?php

declare(strict_types=1);

namespace App\Events;

use App\Contracts\HasRabbitMQPayload;
use App\Models\NotificationSetting;
use App\Models\Task;
use App\Models\User;

class TaskAssigned implements HasRabbitMQPayload
{
    /**
     * @param  array<int>  $userIds  ID назначенных пользователей
     */
    public function __construct(
        public readonly Task $task,
        public readonly array $userIds,
    ) {}

    public function rabbitPayload(): ?array
    {
        $dealershipId = $this->task->dealership_id;

        // Проверяем, включён ли канал для автосалона
        if ($dealershipId && ! NotificationSetting::isChannelEnabled($dealershipId, NotificationSetting::CHANNEL_TASK_ASSIGNED)) {
            return null;
        }

        // Фильтруем получателей по ролям
        $filteredUserIds = $this->filterByRecipientRoles(
            $this->userIds,
            $dealershipId,
            NotificationSetting::CHANNEL_TASK_ASSIGNED,
        );

        if (empty($filteredUserIds)) {
            return null;
        }

        return [
            'event' => 'task.assigned',
            'task' => self::serializeTask($this->task),
            'user_ids' => array_values($filteredUserIds),
            'timestamp' => now()->toIso8601ZuluString(),
        ];
    }

    /**
     * @param  array<int>  $userIds
     * @return array<int>
     */
    private function filterByRecipientRoles(array $userIds, ?int $dealershipId, string $channelType): array
    {
        if (! $dealershipId) {
            return $userIds;
        }

        $allowedRoles = NotificationSetting::getRecipientRoles($dealershipId, $channelType);

        if ($allowedRoles === null || empty($allowedRoles)) {
            return $userIds;
        }

        return User::whereIn('id', $userIds)
            ->whereIn('role', $allowedRoles)
            ->pluck('id')
            ->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public static function serializeTask(Task $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'deadline' => $task->deadline?->toIso8601ZuluString(),
            'priority' => $task->priority,
            'response_type' => $task->response_type,
            'dealership_id' => $task->dealership_id,
        ];
    }
}
