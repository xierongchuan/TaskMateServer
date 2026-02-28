<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

/**
 * Form Request для обновления генератора задач.
 */
class UpdateTaskGeneratorRequest extends BaseApiRequest
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
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'comment' => 'nullable|string',
            'recurrence' => 'sometimes|in:daily,weekly,monthly',
            'recurrence_time' => 'sometimes|date_format:H:i',
            'deadline_time' => 'sometimes|date_format:H:i',
            // Support both old (single int) and new (array) formats for backwards compatibility
            'recurrence_day_of_week' => 'nullable|integer|min:1|max:7',
            'recurrence_day_of_month' => 'nullable|integer|min:-2|max:31',
            'recurrence_days_of_week' => 'nullable|array|max:7',
            'recurrence_days_of_week.*' => 'integer|min:1|max:7',
            'recurrence_days_of_month' => 'nullable|array|max:31',
            'recurrence_days_of_month.*' => 'integer|min:-2|max:31|not_in:0',
            'start_date' => 'sometimes|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'task_type' => 'nullable|in:individual,group',
            'response_type' => 'nullable|in:notification,completion,completion_with_proof',
            'priority' => 'nullable|in:low,medium,high',
            'tags' => 'nullable|array',
            'notification_settings' => 'nullable|array',
            'assignments' => 'sometimes|array|min:1',
            'assignments.*' => 'exists:users,id',
        ];
    }
}
