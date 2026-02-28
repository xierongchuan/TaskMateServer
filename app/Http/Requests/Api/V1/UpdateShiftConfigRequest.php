<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

/**
 * Form Request для обновления конфигурации смен.
 */
class UpdateShiftConfigRequest extends BaseApiRequest
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
            'late_tolerance_minutes' => ['nullable', 'integer', 'min:0', 'max:120'],
            'dealership_id' => ['nullable', 'integer'],
        ];
    }
}
