<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Статусы смен.
 */
enum ShiftStatus: string
{
    case OPEN = 'open';
    case LATE = 'late';
    case CLOSED = 'closed';
    case REPLACED = 'replaced';

    /** Читабельная метка (Ru) */
    public function label(): string
    {
        return match ($this) {
            self::OPEN => 'Открыта',
            self::LATE => 'Открыта (опоздание)',
            self::CLOSED => 'Закрыта',
            self::REPLACED => 'Замещена',
        };
    }

    /** Активные статусы (смена в работе) */
    public static function activeStatuses(): array
    {
        return [self::OPEN, self::LATE];
    }

    /** Активные статусы как строковые значения */
    public static function activeStatusValues(): array
    {
        return array_map(fn ($s) => $s->value, self::activeStatuses());
    }

    /** Закрытые статусы (смена завершена) */
    public static function closedStatuses(): array
    {
        return [self::CLOSED, self::REPLACED];
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
}
