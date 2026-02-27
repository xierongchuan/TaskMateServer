<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Статусы задачи (вычисляемые на основе ответов исполнителей).
 */
enum TaskStatus: string
{
    case PENDING = 'pending';
    case ACKNOWLEDGED = 'acknowledged';
    case PENDING_REVIEW = 'pending_review';
    case COMPLETED = 'completed';
    case COMPLETED_LATE = 'completed_late';
    case OVERDUE = 'overdue';

    /** Читабельная метка (Ru) */
    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Ожидает',
            self::ACKNOWLEDGED => 'Принята',
            self::PENDING_REVIEW => 'На проверке',
            self::COMPLETED => 'Выполнена',
            self::COMPLETED_LATE => 'Выполнена с опозданием',
            self::OVERDUE => 'Просрочена',
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

    /** Статусы которые считаются завершёнными */
    public static function completedStatuses(): array
    {
        return [self::COMPLETED->value, self::COMPLETED_LATE->value];
    }
}
