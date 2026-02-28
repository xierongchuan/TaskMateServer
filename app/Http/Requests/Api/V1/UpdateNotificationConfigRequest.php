<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

/**
 * Form Request для обновления конфигурации уведомлений.
 */
class UpdateNotificationConfigRequest extends BaseApiRequest
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
            'notification_enabled' => ['nullable', 'boolean'],
            'auto_close_shifts' => ['nullable', 'boolean'],
            'shift_reminder_minutes' => ['nullable', 'integer', 'min:1', 'max:60'],
            'rows_per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            'notification_types' => ['nullable', 'array'],
            'dealership_id' => ['nullable', 'integer'],
        ];
    }
}
