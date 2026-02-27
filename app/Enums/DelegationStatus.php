<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Статусы запроса на делегирование задачи.
 */
enum DelegationStatus: string
{
    case PENDING = 'pending';
    case ACCEPTED = 'accepted';
    case REJECTED = 'rejected';
    case CANCELLED = 'cancelled';

    /** Читабельная метка (Ru) */
    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Ожидает',
            self::ACCEPTED => 'Принята',
            self::REJECTED => 'Отклонена',
            self::CANCELLED => 'Отменена',
        };
    }

    /** Все значения для валидации */
    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }

    /** Проверка: является ли статус финальным */
    public function isFinal(): bool
    {
        return in_array($this, [self::ACCEPTED, self::REJECTED, self::CANCELLED]);
    }
}
