<?php

declare(strict_types=1);

namespace App\Traits;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Trait for automatic audit logging of model changes.
 *
 * Автоматически логирует:
 * - dealership_id (если есть в модели или связанной модели)
 * - actor_id (текущий аутентифицированный пользователь)
 *
 * Поддерживаемые модели:
 * - Task (dealership_id напрямую)
 * - TaskResponse (dealership_id через task.dealership_id)
 * - Shift (dealership_id напрямую)
 * - User (dealership_id напрямую)
 * - AutoDealership (id = dealership_id)
 *
 * Usage:
 * ```php
 * class Task extends Model
 * {
 *     use Auditable;
 * }
 * ```
 */
trait Auditable
{
    /**
     * Boot the auditable trait for a model.
     */
    public static function bootAuditable(): void
    {
        static::created(function (Model $model) {
            self::logAudit($model, 'created', $model->toArray());
        });

        static::updated(function (Model $model) {
            $changes = $model->getChanges();
            // Remove updated_at from changes since it's automatic
            unset($changes['updated_at']);

            if (!empty($changes)) {
                self::logAudit($model, 'updated', [
                    'old' => array_intersect_key($model->getOriginal(), $changes),
                    'new' => $changes,
                ]);
            }
        });

        static::deleted(function (Model $model) {
            self::logAudit($model, 'deleted', $model->toArray());
        });
    }

    /**
     * Извлекает dealership_id из модели.
     *
     * Приоритет:
     * 1. Если модель - AutoDealership, НЕ записываем dealership_id (чтобы избежать FK constraint при удалении)
     * 2. Если у модели есть dealership_id - возвращает его
     * 3. Если у модели есть связь task с dealership_id - возвращает его (TaskResponse)
     *
     * @param Model $model
     * @return int|null
     */
    protected static function extractDealershipId(Model $model): ?int
    {
        // Для AutoDealership не записываем dealership_id (избегаем FK constraint при удалении)
        // Информация о автосалоне уже есть в record_id и payload
        if ($model->getTable() === 'auto_dealerships') {
            return null;
        }

        // Прямое поле dealership_id
        if (isset($model->dealership_id)) {
            return $model->dealership_id;
        }

        // Для TaskResponse: получить dealership_id через связанную задачу
        if ($model->getTable() === 'task_responses') {
            $task = $model->relationLoaded('task')
                ? $model->task
                : $model->task()->first();
            if ($task && isset($task->dealership_id)) {
                return $task->dealership_id;
            }
        }

        return null;
    }

    /**
     * Проверяет, похожа ли строка на дату в формате Y-m-d H:i:s.
     */
    protected static function isDateString(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) === 1;
    }

    /**
     * Recursively convert all datetime values in payload to ISO 8601 Zulu format.
     *
     * Обрабатывает:
     * - DateTimeInterface объекты (редко, но на всякий случай)
     * - Строки дат из toArray() в формате "Y-m-d H:i:s" (уже в UTC)
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    protected static function formatPayloadDates(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if ($value instanceof \DateTimeInterface) {
                // Carbon объект
                $payload[$key] = \Carbon\Carbon::instance($value)->setTimezone('UTC')->toIso8601ZuluString();
            } elseif (is_string($value) && self::isDateString($value)) {
                // Строка даты из toArray() — уже в UTC (Laravel хранит в UTC)
                $payload[$key] = \Carbon\Carbon::parse($value, 'UTC')->toIso8601ZuluString();
            } elseif (is_array($value)) {
                $payload[$key] = self::formatPayloadDates($value);
            }
        }

        return $payload;
    }

    /**
     * Log an audit entry for the model.
     *
     * @param Model $model The model instance
     * @param string $action The action (created, updated, deleted)
     * @param array<string, mixed> $payload The data to log
     */
    protected static function logAudit(Model $model, string $action, array $payload): void
    {
        try {
            AuditLog::create([
                'table_name' => $model->getTable(),
                'record_id' => $model->getKey(),
                'actor_id' => auth()->id(),
                'dealership_id' => self::extractDealershipId($model),
                'action' => $action,
                'payload' => self::formatPayloadDates($payload),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Silent fail - don't break the main operation
            \Log::warning('Audit log failed: ' . $e->getMessage());
        }
    }

    /**
     * Get audit history for this model.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, AuditLog>
     */
    public function auditHistory(): \Illuminate\Database\Eloquent\Collection
    {
        return AuditLog::where('table_name', $this->getTable())
            ->where('record_id', $this->getKey())
            ->orderByDesc('created_at')
            ->get();
    }
}
