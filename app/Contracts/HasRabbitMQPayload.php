<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Интерфейс для событий, публикуемых в RabbitMQ.
 */
interface HasRabbitMQPayload
{
    /**
     * Получить payload для публикации в RabbitMQ.
     *
     * Возвращает null, если событие не должно быть опубликовано
     * (например, канал отключён в настройках уведомлений).
     *
     * @return array<string, mixed>|null
     */
    public function rabbitPayload(): ?array;
}
