<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form Request для массового обновления настроек уведомлений.
 */
class BulkUpdateNotificationSettingRequest extends FormRequest
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
            'dealership_id' => 'sometimes|exists:auto_dealerships,id',
            'settings' => 'required|array',
            'settings.*.channel_type' => 'required|string',
            'settings.*.is_enabled' => 'sometimes|boolean',
            'settings.*.notification_time' => 'nullable|date_format:H:i',
            'settings.*.notification_day' => 'nullable|string',
        ];
    }
}
