<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

/**
 * Form Request для обновления/закрытия смены.
 */
class UpdateShiftRequest extends BaseApiRequest
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
}
