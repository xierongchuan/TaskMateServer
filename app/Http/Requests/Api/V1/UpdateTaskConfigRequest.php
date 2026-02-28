<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

/**
 * Form Request для обновления конфигурации задач.
 */
class UpdateTaskConfigRequest extends BaseApiRequest
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
            'task_requires_open_shift' => ['nullable', 'boolean'],
            'archive_overdue_hours_after_shift' => ['nullable', 'integer', 'min:1', 'max:48'],
            'dealership_id' => ['nullable', 'integer'],
        ];
    }
}
