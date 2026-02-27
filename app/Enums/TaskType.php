<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Типы задач (индивидуальная vs групповая).
 */
enum TaskType: string
{
    case INDIVIDUAL = 'individual';
    case GROUP = 'group';

    /** Читабельная метка (Ru) */
    public function label(): string
    {
        return match ($this) {
            self::INDIVIDUAL => 'Индивидуальная',
            self::GROUP => 'Групповая',
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
