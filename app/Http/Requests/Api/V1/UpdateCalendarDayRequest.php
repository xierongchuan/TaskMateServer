<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

/**
 * Form Request для обновления дня календаря.
 */
class UpdateCalendarDayRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true; // route middleware: role:manager,owner
    }

    public function validationData(): array
    {
        return array_merge($this->all(), ['date' => $this->route('date')]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date' => 'required|date_format:Y-m-d',
            'type' => 'required|in:holiday,workday',
            'description' => 'nullable|string|max:255',
            'dealership_id' => 'nullable|integer|exists:auto_dealerships,id',
        ];
    }
}
