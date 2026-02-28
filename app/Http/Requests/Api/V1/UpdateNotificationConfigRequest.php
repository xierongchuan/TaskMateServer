<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Form Request для обновления конфигурации уведомлений.
 */
class UpdateNotificationConfigRequest extends FormRequest
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

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422)
        );
    }
}
