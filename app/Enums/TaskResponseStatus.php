<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Статусы ответа на задачу (TaskResponse).
 *
 * Отличается от TaskStatus, который вычисляется на основе responses.
 * TaskResponseStatus - это фактические статусы хранящиеся в БД.
 */
enum TaskResponseStatus: string
{
    case PENDING = 'pending';
    case ACKNOWLEDGED = 'acknowledged';
    case PENDING_REVIEW = 'pending_review';
    case COMPLETED = 'completed';
    case REJECTED = 'rejected';

    /** Читабельная метка (Ru) */
    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Ожидает',
            self::ACKNOWLEDGED => 'Принята',
            self::PENDING_REVIEW => 'На проверке',
            self::COMPLETED => 'Выполнена',
            self::REJECTED => 'Отклонена',
        };
    }

    /** Все значения для валидации */
    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }

    /** Безопасный парсинг из строки */
    public static function tryFromString(?string $v): ?self
    {
        return $v === null ? null : self::tryFrom($v);
    }

    /** Статусы, допустимые для входящих запросов updateStatus */
    public static function allowedForUpdateStatus(): array
    {
        return [
            self::PENDING->value,
            self::ACKNOWLEDGED->value,
            self::PENDING_REVIEW->value,
            self::COMPLETED->value,
        ];
    }

    /** Проверка является ли статус финальным (нельзя перейти к другому) */
    public function isFinal(): bool
    {
        return $this === self::COMPLETED;
    }

    /** Статусы требующие верификации */
    public static function requiresVerification(): array
    {
        return [self::PENDING_REVIEW->value];
    }
}
