<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

/**
 * Form Request для обновления конфигурации архивации.
 */
class UpdateArchiveConfigRequest extends BaseApiRequest
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
            'archive_completed_time' => ['nullable', 'string', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/'],
            'archive_overdue_day_of_week' => ['nullable', 'integer', 'min:0', 'max:7'],
            'archive_overdue_time' => ['nullable', 'string', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/'],
            'dealership_id' => ['nullable', 'integer'],
        ];
    }
}
