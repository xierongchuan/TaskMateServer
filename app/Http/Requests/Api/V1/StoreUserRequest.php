<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Form Request для создания пользователя.
 */
class StoreUserRequest extends FormRequest
{
    /**
     * Определяет, авторизован ли пользователь для этого запроса.
     * Только manager и owner могут создавать пользователей.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        if (!$user) {
            return false;
        }

        return in_array($user->role, [Role::MANAGER, Role::OWNER]);
    }

    /**
     * Правила валидации для создания пользователя.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'login' => [
                'required',
                'string',
                'min:4',
                'max:64',
                'unique:users,login',
                'regex:/^(?!.*\..*\.)(?!.*_.*_)[a-zA-Z0-9._]+$/',
            ],
            'password' => [
                'required',
                'string',
                'min:8',
                'max:255',
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/',
            ],
            'full_name' => [
                'required',
                'string',
                'min:2',
                'max:255',
            ],
            'phone' => [
                'required',
                'string',
                'regex:/^\+?[\d\s\-\(\)]+$/',
                'max:20',
            ],
            'role' => [
                'required',
                'string',
                Rule::in(Role::values()),
            ],
            'dealership_id' => [
                'nullable',
                'integer',
                'exists:auto_dealerships,id',
            ],
            'dealership_ids' => [
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
            'login.required' => 'Логин обязателен',
            'login.min' => 'Логин должен содержать минимум 4 символа',
            'login.max' => 'Логин не может превышать 64 символа',
            'login.regex' => 'Логин может содержать только латинские буквы, цифры, одну точку и одно нижнее подчеркивание',
            'login.unique' => 'Такой логин уже существует',
            'password.required' => 'Пароль обязателен',
            'password.min' => 'Пароль должен содержать минимум 8 символов',
            'password.regex' => 'Пароль должен содержать минимум одну заглавную букву, одну строчную букву и одну цифру',
            'full_name.required' => 'Полное имя обязательно',
            'full_name.min' => 'Полное имя должно содержать минимум 2 символа',
            'phone.required' => 'Телефон обязателен',
            'phone.regex' => 'Некорректный формат телефона',
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
