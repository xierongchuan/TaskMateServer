<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Предустановленные диапазоны дат для фильтрации.
 */
enum DateRange: string
{
    case ALL = 'all';
    case TODAY = 'today';
    case WEEK = 'week';
    case MONTH = 'month';

    /** Читабельная метка (Ru) */
    public function label(): string
    {
        return match ($this) {
            self::ALL => 'Все',
            self::TODAY => 'Сегодня',
            self::WEEK => 'Неделя',
            self::MONTH => 'Месяц',
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
}
