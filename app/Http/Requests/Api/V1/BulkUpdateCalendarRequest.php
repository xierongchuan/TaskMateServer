<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Form Request для массового обновления дней календаря.
 */
class BulkUpdateCalendarRequest extends FormRequest
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
            'operation' => 'required|in:set_weekdays,set_dates,clear_year',
            'year' => 'required|integer|min:2020|max:2100',
            'dealership_id' => 'nullable|integer|exists:auto_dealerships,id',

            // Для set_weekdays
            'weekdays' => 'required_if:operation,set_weekdays|array',
            'weekdays.*' => 'integer|min:1|max:7',

            // Для set_dates
            'dates' => 'required_if:operation,set_dates|array',
            'dates.*' => 'date_format:Y-m-d',

            // Общие параметры
            'type' => 'required_if:operation,set_weekdays,set_dates|in:holiday,workday',
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
