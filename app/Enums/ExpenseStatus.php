<?php

declare(strict_types=1);

namespace App\Enums;

enum ExpenseStatus: string
{
    case PENDING   = 'pending';
    case APPROVED  = 'approved';
    case DECLINED  = 'declined';
    case ISSUED    = 'issued';
    case CANCELLED = 'cancelled';

    /** Читабельная метка (RU) */
    public function label(): string
    {
        return match ($this) {
            self::PENDING  => 'Ожидает руководителя',
            self::APPROVED => 'Одобрено руководителем',
            self::DECLINED => 'Отклонено руководителем',
            self::ISSUED           => 'Выдано (бухгалтер)',
            self::CANCELLED        => 'Отменено',
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
