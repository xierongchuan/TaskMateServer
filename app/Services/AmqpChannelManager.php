<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;

/**
 * Управляет AMQP-соединением и каналом для публикации событий.
 *
 * Зарегистрирован как singleton в AppServiceProvider — одно соединение на
 * весь жизненный цикл процесса. При сбое соединения или канала выполняется
 * graceful reconnect перед следующей публикацией.
 */
final class AmqpChannelManager
{
    private ?AMQPStreamConnection $connection = null;

    /** @var AMQPChannel|null */
    private $channel = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $user,
        private readonly string $password,
        private readonly string $vhost,
    ) {}

    /**
     * Вернуть готовый канал. При необходимости пересоздаёт соединение/канал.
     *
     * @throws \Throwable — пробрасывает исключение при невозможности подключиться
     */
    public function channel(string $exchange, string $exchangeType = 'fanout'): AMQPChannel
    {
        if ($this->isChannelOpen()) {
            return $this->channel; // @phpstan-ignore-line (checked by isChannelOpen)
        }

        $this->reconnect($exchange, $exchangeType);

        return $this->channel; // @phpstan-ignore-line
    }

    /**
     * Сбросить соединение и канал (вызывается снаружи при поймке ошибки публикации).
     */
    public function invalidate(): void
    {
        $this->channel = null;
        $this->connection = null;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private function isChannelOpen(): bool
    {
        return $this->channel !== null
            && $this->channel->is_open()
            && $this->connection !== null
            && $this->connection->isConnected();
    }

    private function reconnect(string $exchange, string $exchangeType): void
    {
        $this->tryCloseExisting();

        Log::debug('AmqpChannelManager: устанавливаем соединение с RabbitMQ', [
            'host' => $this->host,
            'port' => $this->port,
        ]);

        $this->connection = new AMQPStreamConnection(
            $this->host,
            $this->port,
            $this->user,
            $this->password,
            $this->vhost,
        );

        $this->channel = $this->connection->channel();
        $this->channel->exchange_declare($exchange, $exchangeType, false, true, false);
    }

    private function tryCloseExisting(): void
    {
        try {
            if ($this->channel !== null) {
                $this->channel->close();
            }
        } catch (\Throwable) {
            // Игнорируем ошибки при закрытии сломанного канала
        }

        try {
            if ($this->connection !== null) {
                $this->connection->close();
            }
        } catch (\Throwable) {
            // Игнорируем ошибки при закрытии сломанного соединения
        }

        $this->channel = null;
        $this->connection = null;
    }
}
