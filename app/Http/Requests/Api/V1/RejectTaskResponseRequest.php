<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form Request для отклонения ответа на задачу.
 *
 * Используется в TaskVerificationController::reject и rejectAll.
 */
class RejectTaskResponseRequest extends FormRequest
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
            'reason' => 'required|string|max:1000',
        ];
    }
}
