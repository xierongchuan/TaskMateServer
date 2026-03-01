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
use App\Services\AmqpChannelManager;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Подписчик, публикующий события задач в RabbitMQ exchange.
 *
 * Потребители: Telegram Bot и другие внешние сервисы.
 *
 * AmqpChannelManager инжектируется как DI-singleton — одно физическое
 * соединение на жизненный цикл процесса с поддержкой graceful reconnect.
 */
class PublishTaskEventSubscriber
{
    private const EXCHANGE_NAME = 'task_events';

    public function __construct(
        private readonly AmqpChannelManager $amqp,
    ) {}

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

    /**
     * @param  array<string, mixed>  $payload
     */
    private function publishToRabbitMQ(array $payload): void
    {
        try {
            $channel = $this->amqp->channel(self::EXCHANGE_NAME);

            $message = new AMQPMessage(
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ['content_type' => 'application/json', 'delivery_mode' => 2],
            );

            $channel->basic_publish($message, self::EXCHANGE_NAME);
        } catch (\Throwable $e) {
            // Сбрасываем соединение, чтобы следующая попытка выполнила reconnect
            $this->amqp->invalidate();

            Log::warning('PublishTaskEventSubscriber: не удалось опубликовать событие', [
                'error' => $e->getMessage(),
                'event' => $payload['event'] ?? 'unknown',
            ]);
        }
    }
}
