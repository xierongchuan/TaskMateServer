<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Типы повторения задач.
 */
enum Recurrence: string
{
    case NONE = 'none';
    case DAILY = 'daily';
    case WEEKLY = 'weekly';
    case MONTHLY = 'monthly';

    /** Читабельная метка (Ru) */
    public function label(): string
    {
        return match ($this) {
            self::NONE => 'Без повторения',
            self::DAILY => 'Ежедневно',
            self::WEEKLY => 'Еженедельно',
            self::MONTHLY => 'Ежемесячно',
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

    /** Проверяет является ли значение повторяющимся */
    public function isRecurring(): bool
    {
        return $this !== self::NONE;
    }
}
