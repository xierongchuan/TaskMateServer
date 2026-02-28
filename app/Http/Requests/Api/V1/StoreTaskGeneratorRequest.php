<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\Validator;

/**
 * Form Request для создания генератора задач.
 */
class StoreTaskGeneratorRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true; // route middleware: role:manager,owner
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'comment' => 'nullable|string',
            'dealership_id' => 'required|exists:auto_dealerships,id',
            'recurrence' => 'required|in:daily,weekly,monthly',
            'recurrence_time' => 'required|date_format:H:i',
            'deadline_time' => 'required|date_format:H:i',
            // Support both old (single int) and new (array) formats for backwards compatibility
            'recurrence_day_of_week' => 'nullable|integer|min:1|max:7',
            'recurrence_day_of_month' => 'nullable|integer|min:-2|max:31',
            'recurrence_days_of_week' => 'nullable|array|max:7',
            'recurrence_days_of_week.*' => 'integer|min:1|max:7',
            'recurrence_days_of_month' => 'nullable|array|max:31',
            'recurrence_days_of_month.*' => 'integer|min:-2|max:31|not_in:0',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'task_type' => 'nullable|in:individual,group',
            'response_type' => 'nullable|in:notification,completion,completion_with_proof',
            'priority' => 'nullable|in:low,medium,high',
            'tags' => 'nullable|array',
            'notification_settings' => 'nullable|array',
            'assignments' => 'required|array|min:1',
            'assignments.*' => 'exists:users,id',
        ];
    }

    /**
     * Дополнительная валидация после прохождения базовых правил.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $recurrence = $this->input('recurrence');
            $daysOfWeek = $this->input('recurrence_days_of_week');
            $dayOfWeek = $this->input('recurrence_day_of_week');
            $daysOfMonth = $this->input('recurrence_days_of_month');
            $dayOfMonth = $this->input('recurrence_day_of_month');

            // Resolve backwards compatibility: old single-value to array
            if (empty($daysOfWeek) && ! empty($dayOfWeek)) {
                $daysOfWeek = [$dayOfWeek];
            }
            if (empty($daysOfMonth) && ! empty($dayOfMonth)) {
                $daysOfMonth = [$dayOfMonth];
            }

            if ($recurrence === 'weekly' && empty($daysOfWeek)) {
                $validator->errors()->add(
                    'recurrence_days_of_week',
                    'recurrence_days_of_week is required for weekly recurrence'
                );
            }

            if ($recurrence === 'monthly' && empty($daysOfMonth)) {
                $validator->errors()->add(
                    'recurrence_days_of_month',
                    'recurrence_days_of_month is required for monthly recurrence'
                );
            }

            // Валидация типа задачи и количества исполнителей
            $taskType = $this->input('task_type', 'individual');
            $assignments = $this->input('assignments', []);
            $assignmentCount = is_array($assignments) ? count($assignments) : 0;

            if ($taskType === 'group' && $assignmentCount === 0) {
                $validator->errors()->add(
                    'assignments',
                    'Для групповой задачи необходимо указать хотя бы одного исполнителя'
                );
            }

            if ($taskType === 'individual' && $assignmentCount > 1) {
                $validator->errors()->add(
                    'task_type',
                    'Индивидуальная задача не может иметь более одного исполнителя. Используйте групповую задачу для нескольких исполнителей.'
                );
            }
        });
    }
}
