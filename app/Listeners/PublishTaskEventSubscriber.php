<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Contracts\HasRabbitMQPayload;
use App\Events\DelegationAccepted;
use App\Events\DelegationRejected;
use App\Events\DelegationRequested;
use App\Events\TaskApproved;
use App\Events\TaskAssigned;
use App\Events\TaskPendingReview;
use App\Events\TaskRejected;
use App\Events\TaskRejectedBulk;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Подписчик, публикующий события задач в RabbitMQ exchange.
 *
 * Потребители: Telegram Bot и другие внешние сервисы.
 */
class PublishTaskEventSubscriber
{
    private const EXCHANGE_NAME = 'task_events';

    private static ?AMQPStreamConnection $connection = null;

    /** @var \PhpAmqpLib\Channel\AMQPChannel|null */
    private static $channel = null;

    public function handle(HasRabbitMQPayload $event): void
    {
        $payload = $event->rabbitPayload();

        if ($payload === null) {
            return;
        }

        $this->publishToRabbitMQ($payload);
    }

    /**
     * @return array<string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            TaskAssigned::class => 'handle',
            TaskApproved::class => 'handle',
            TaskRejected::class => 'handle',
            TaskPendingReview::class => 'handle',
            TaskRejectedBulk::class => 'handle',
            DelegationRequested::class => 'handle',
            DelegationAccepted::class => 'handle',
            DelegationRejected::class => 'handle',
        ];
    }

    private function publishToRabbitMQ(array $payload): void
    {
        try {
            $channel = self::getChannel();

            $message = new AMQPMessage(
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ['content_type' => 'application/json', 'delivery_mode' => 2],
            );

            $channel->basic_publish($message, self::EXCHANGE_NAME);
        } catch (\Throwable $e) {
            self::$connection = null;
            self::$channel = null;

            Log::warning('PublishTaskEventSubscriber: не удалось опубликовать событие', [
                'error' => $e->getMessage(),
                'event' => $payload['event'] ?? 'unknown',
            ]);
        }
    }

    private static function getChannel()
    {
        if (self::$channel !== null && self::$channel->is_open()) {
            return self::$channel;
        }

        $connection = self::getConnection();
        self::$channel = $connection->channel();
        self::$channel->exchange_declare(self::EXCHANGE_NAME, 'fanout', false, true, false);

        return self::$channel;
    }

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
}
