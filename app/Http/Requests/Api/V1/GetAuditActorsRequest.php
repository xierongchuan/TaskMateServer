<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class GetAuditActorsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Авторизация выполняется через middleware role:owner
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'dealership_id' => [
                'nullable',
                'integer',
                'min:1',
                'exists:auto_dealerships,id',
            ],
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'dealership_id.integer' => 'ID автосалона должен быть числом',
            'dealership_id.min' => 'ID автосалона должен быть больше 0',
            'dealership_id.exists' => 'Автосалон с таким ID не найден',
        ];
    }
}
