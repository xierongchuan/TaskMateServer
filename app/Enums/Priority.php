<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Приоритеты задач.
 */
enum Priority: string
{
    case LOW = 'low';
    case MEDIUM = 'medium';
    case HIGH = 'high';

    /** Читабельная метка (Ru) */
    public function label(): string
    {
        return match ($this) {
            self::LOW => 'Низкий',
            self::MEDIUM => 'Средний',
            self::HIGH => 'Высокий',
        };
    }

    /** Уровень приоритета для сортировки (больше = выше приоритет) */
    public function level(): int
    {
        return match ($this) {
            self::LOW => 1,
            self::MEDIUM => 2,
            self::HIGH => 3,
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

    /** Значение по умолчанию */
    public static function default(): self
    {
        return self::MEDIUM;
    }
}
