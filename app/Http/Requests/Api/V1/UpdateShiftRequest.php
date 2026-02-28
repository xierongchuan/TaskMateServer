<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Form Request для обновления/закрытия смены.
 */
class UpdateShiftRequest extends FormRequest
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
            'closing_photo' => 'sometimes|required|file|image|mimes:jpeg,png,jpg|max:5120',
            'status' => 'sometimes|in:open,closed',
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
