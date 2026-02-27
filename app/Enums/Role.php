<?php

declare(strict_types=1);

namespace App\Enums;

enum Role: string
{
    case OWNER  = 'owner';
    case MANAGER  = 'manager';
    case OBSERVER  = 'observer';
    case EMPLOYEE  = 'employee';

    /** Читабельная метка (Ru) */
    public function label(): string
    {
        return match ($this) {
            self::OWNER => 'Владелец',
            self::MANAGER => 'Управляющий',
            self::OBSERVER => 'Смотрящий',
            self::EMPLOYEE => 'Сотрудник',
        };
    }

    /** Уровень доступа (больше = выше права) */
    public function level(): int
    {
        return match ($this) {
            self::OWNER => 4,
            self::MANAGER => 3,
            self::OBSERVER => 2,
            self::EMPLOYEE => 1,
        };
    }

    /** все значения для миграции / проверок */
    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }

    /** безопасный парсинг из строки — вернёт null если неверно */
    public static function tryFromString(?string $v): ?self
    {
        return $v === null ? null : self::tryFrom($v);
    }
}
