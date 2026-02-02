<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NotificationSetting;
use App\Models\Task;
use App\Models\TaskResponse;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Публикует события задач в RabbitMQ exchange для внешних потребителей (Telegram Bot и др.).
 *
 * Проверяет NotificationSetting перед публикацией:
 * - is_enabled — включён ли канал для автосалона
 * - recipient_roles — фильтрация получателей по ролям
 */
class TaskEventPublisher
{
    private const EXCHANGE_NAME = 'task_events';

    private static ?AMQPStreamConnection $connection = null;

    /**
     * Опубликовать событие назначения задачи.
     *
     * @param Task $task Созданная/обновлённая задача
     * @param array<int> $userIds ID назначенных пользователей
     */
    public static function publishTaskAssigned(Task $task, array $userIds): void
    {
        $dealershipId = $task->dealership_id;

        // Проверяем, включён ли канал для автосалона
        if ($dealershipId && !NotificationSetting::isChannelEnabled($dealershipId, NotificationSetting::CHANNEL_TASK_ASSIGNED)) {
            return;
        }

        // Фильтруем получателей по ролям
        $filteredUserIds = self::filterByRecipientRoles(
            $userIds,
            $dealershipId,
            NotificationSetting::CHANNEL_TASK_ASSIGNED,
        );

        if (empty($filteredUserIds)) {
            return;
        }

        self::publish([
            'event' => 'task.assigned',
            'task' => self::serializeTask($task),
            'user_ids' => array_values($filteredUserIds),
            'timestamp' => now()->toIso8601ZuluString(),
        ]);
    }

    /**
     * Опубликовать событие одобрения ответа.
     * Критическое уведомление — всегда отправляется (не зависит от is_enabled).
     */
    public static function publishTaskApproved(TaskResponse $response): void
    {
        $response->loadMissing(['task', 'user']);

        self::publish([
            'event' => 'task.approved',
            'task' => self::serializeTask($response->task),
            'user_ids' => [$response->user_id],
            'timestamp' => now()->toIso8601ZuluString(),
        ]);
    }

    /**
     * Опубликовать событие отклонения ответа.
     * Критическое уведомление — всегда отправляется.
     */
    public static function publishTaskRejected(TaskResponse $response, string $reason): void
    {
        $response->loadMissing(['task', 'user']);

        self::publish([
            'event' => 'task.rejected',
            'task' => self::serializeTask($response->task),
            'user_ids' => [$response->user_id],
            'reason' => $reason,
            'timestamp' => now()->toIso8601ZuluString(),
        ]);
    }

    /**
     * Опубликовать событие отправки на проверку.
     * Критическое уведомление — всегда отправляется.
     * Отправляется менеджерам и владельцам автосалона.
     */
    public static function publishTaskPendingReview(TaskResponse $response): void
    {
        $response->loadMissing(['task', 'user']);
        $task = $response->task;

        // Получаем менеджеров и владельцев автосалона
        $managerOwnerIds = User::where(function ($query) use ($task) {
            $query->where('dealership_id', $task->dealership_id)
                ->orWhereHas('dealerships', function ($q) use ($task) {
                    $q->where('auto_dealerships.id', $task->dealership_id);
                });
        })
            ->whereIn('role', ['manager', 'owner'])
            ->where('id', '!=', $response->user_id)
            ->pluck('id')
            ->toArray();

        if (empty($managerOwnerIds)) {
            return;
        }

        self::publish([
            'event' => 'task.pending_review',
            'task' => self::serializeTask($task),
            'user_ids' => array_values($managerOwnerIds),
            'submitted_by' => $response->user->full_name ?? 'Сотрудник',
            'response_id' => $response->id,
            'timestamp' => now()->toIso8601ZuluString(),
        ]);
    }

    /**
     * Опубликовать событие группового отклонения.
     * Критическое уведомление — всегда отправляется.
     */
    public static function publishTaskRejectedBulk(Task $task, array $userIds, string $reason): void
    {
        self::publish([
            'event' => 'task.rejected',
            'task' => self::serializeTask($task),
            'user_ids' => array_values($userIds),
            'reason' => $reason,
            'timestamp' => now()->toIso8601ZuluString(),
        ]);
    }

    /**
     * Отфильтровать user_ids по recipient_roles из NotificationSetting.
     *
     * @param array<int> $userIds
     * @return array<int>
     */
    private static function filterByRecipientRoles(array $userIds, ?int $dealershipId, string $channelType): array
    {
        if (!$dealershipId) {
            return $userIds;
        }

        $allowedRoles = NotificationSetting::getRecipientRoles($dealershipId, $channelType);

        // Если роли не настроены, отправляем всем
        if ($allowedRoles === null || empty($allowedRoles)) {
            return $userIds;
        }

        return User::whereIn('id', $userIds)
            ->whereIn('role', $allowedRoles)
            ->pluck('id')
            ->toArray();
    }

    /**
     * Сериализовать задачу для сообщения.
     *
     * @return array<string, mixed>
     */
    private static function serializeTask(Task $task): array
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

    /**
     * Получить или создать AMQP-соединение (singleton).
     */
    private static function getConnection(): AMQPStreamConnection
    {
        if (self::$connection !== null && self::$connection->isConnected()) {
            return self::$connection;
        }

        $config = config('queue.connections.rabbitmq.hosts.0');

        self::$connection = new AMQPStreamConnection(
            $config['host'],
            $config['port'],
            $config['user'],
            $config['password'],
            $config['vhost'] ?? '/',
        );

        return self::$connection;
    }

    /**
     * Опубликовать сообщение в RabbitMQ exchange.
     *
     * @param array<string, mixed> $payload
     */
    private static function publish(array $payload): void
    {
        try {
            $connection = self::getConnection();
            $channel = $connection->channel();
            $channel->exchange_declare(self::EXCHANGE_NAME, 'fanout', false, true, false);

            $message = new AMQPMessage(
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ['content_type' => 'application/json', 'delivery_mode' => 2],
            );

            $channel->basic_publish($message, self::EXCHANGE_NAME);
            $channel->close();
        } catch (\Throwable $e) {
            // Сбросить соединение при ошибке для переподключения на следующем вызове
            self::$connection = null;

            Log::warning('TaskEventPublisher: не удалось опубликовать событие', [
                'error' => $e->getMessage(),
                'event' => $payload['event'] ?? 'unknown',
            ]);
        }
    }
}
