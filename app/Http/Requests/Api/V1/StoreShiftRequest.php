<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

/**
 * Form Request для открытия смены.
 */
class StoreShiftRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true; // auth:sanctum middleware
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user_id' => 'required|exists:users,id',
            'dealership_id' => 'required|exists:auto_dealerships,id',
            'opening_photo' => 'required|file|image|mimes:jpeg,png,jpg|max:5120',
            'shift_schedule_id' => 'nullable|integer|exists:shift_schedules,id',
        ];
    }
}
