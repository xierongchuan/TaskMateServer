<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Form Request для обновления пользователя.
 */
class UpdateUserRequest extends FormRequest
{
    /**
     * Определяет, авторизован ли пользователь для этого запроса.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Правила валидации для обновления пользователя.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_password' => [
                'sometimes',
                'nullable',
                'string',
            ],
            'password' => [
                'sometimes',
                'nullable',
                'string',
                'min:8',
                'max:255',
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)[A-Za-z\d@$!%*?&]/',
            ],
            'full_name' => [
                'sometimes',
                'required',
                'string',
                'min:2',
                'max:255',
            ],
            'phone' => [
                'sometimes',
                'required',
                'string',
                'regex:/^\+?[\d\s\-\(\)]+$/',
                'max:20',
            ],
            'phone_number' => [
                'sometimes',
                'required',
                'string',
                'regex:/^\+?[\d\s\-\(\)]+$/',
                'max:20',
            ],
            'role' => [
                'sometimes',
                'required',
                'string',
                Rule::in(Role::values()),
            ],
            'dealership_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:auto_dealerships,id',
            ],
            'dealership_ids' => [
                'sometimes',
                'nullable',
                'array',
            ],
            'dealership_ids.*' => [
                'integer',
                'exists:auto_dealerships,id',
            ],
        ];
    }

    /**
     * Сообщения об ошибках валидации.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.min' => 'Пароль должен содержать минимум 8 символов',
            'password.regex' => 'Пароль должен содержать минимум одну заглавную букву, одну строчную букву и одну цифру',
            'full_name.required' => 'Полное имя обязательно',
            'full_name.min' => 'Полное имя должно содержать минимум 2 символа',
            'phone.required' => 'Телефон обязателен',
            'phone.regex' => 'Некорректный формат телефона',
            'phone_number.required' => 'Телефон обязателен',
            'phone_number.regex' => 'Некорректный формат телефона',
            'role.required' => 'Роль обязательна',
            'role.in' => 'Некорректная роль',
            'dealership_id.exists' => 'Автосалон не найден',
            'dealership_ids.*.exists' => 'Один из автосалонов не найден',
        ];
    }

    /**
     * Обработка неуспешной валидации.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422)
        );
    }
}
