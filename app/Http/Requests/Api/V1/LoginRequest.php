<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form Request для аутентификации.
 */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'login' => ['required', 'min:4', 'max:64', 'regex:/^(?!.*\..*\.)(?!.*_.*_)[a-zA-Z0-9._]+$/'],
            'password' => 'required|min:6|max:255',
        ];
    }
}
