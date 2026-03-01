<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Правило валидации: количество исполнителей должно соответствовать типу задачи.
 *
 * - individual: не более 1 исполнителя
 * - group: не менее 1 исполнителя
 */
class ValidAssignmentsForTaskType implements ValidationRule
{
    /**
     * @param  string  $taskType  Тип задачи: 'individual' или 'group'
     * @param  int  $currentCount  Текущее количество исполнителей (для update-контекста, когда assignments не переданы)
     */
    public function __construct(
        private readonly string $taskType,
        private readonly int $currentCount = 0,
    ) {}

    /**
     * Валидация количества исполнителей относительно типа задачи.
     *
     * @param  mixed  $value  Массив ID исполнителей или null
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Если assignments не переданы в запросе — используем текущее количество из БД
        if ($value === null) {
            $assignmentCount = $this->currentCount;
        } else {
            $assignmentCount = is_array($value) ? count($value) : 0;
        }

        if ($this->taskType === 'group' && $assignmentCount === 0) {
            $fail('Для групповой задачи необходимо указать хотя бы одного исполнителя');

            return;
        }

        if ($this->taskType === 'individual' && $assignmentCount > 1) {
            $fail('Индивидуальная задача не может иметь более одного исполнителя. Используйте групповую задачу для нескольких исполнителей.');
        }
    }
}
