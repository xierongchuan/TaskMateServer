<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form Request для обновления настройки уведомлений.
 */
class UpdateNotificationSettingRequest extends FormRequest
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
            'is_enabled' => 'sometimes|boolean',
            'notification_time' => 'nullable|date_format:H:i',
            'notification_day' => 'nullable|string|in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'notification_offset' => 'nullable|integer|min:1|max:1440',
            'recipient_roles' => 'nullable|array',
            'recipient_roles.*' => 'string|in:employee,manager,owner,observer',
        ];
    }
}
