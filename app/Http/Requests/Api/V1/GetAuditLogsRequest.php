<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class GetAuditLogsRequest extends FormRequest
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
            'log_id' => [
                'nullable',
                'integer',
                'min:1',
            ],
            'table_name' => [
                'nullable',
                'string',
                'in:tasks,task_responses,shifts,users,auto_dealerships',
            ],
            'action' => [
                'nullable',
                'string',
                'in:created,updated,deleted',
            ],
            'actor_id' => [
                'nullable',
                'integer',
                'min:1',
                'exists:users,id',
            ],
            'dealership_id' => [
                'nullable',
                'integer',
                'min:1',
                'exists:auto_dealerships,id',
            ],
            'from_date' => [
                'nullable',
                'date_format:Y-m-d',
            ],
            'to_date' => [
                'nullable',
                'date_format:Y-m-d',
                'after_or_equal:from_date',
            ],
            'record_id' => [
                'nullable',
                'integer',
                'min:1',
            ],
            'page' => [
                'nullable',
                'integer',
                'min:1',
            ],
            'per_page' => [
                'nullable',
                'integer',
                'min:1',
                'max:100',
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
            'log_id.integer' => 'ID журнала должен быть числом',
            'log_id.min' => 'ID журнала должен быть больше 0',
            'table_name.in' => 'Неверный тип таблицы. Допустимые значения: tasks, task_responses, shifts, users, auto_dealerships',
            'action.in' => 'Неверное действие. Допустимые значения: created, updated, deleted',
            'actor_id.exists' => 'Пользователь с таким ID не найден',
            'actor_id.integer' => 'ID пользователя должен быть числом',
            'actor_id.min' => 'ID пользователя должен быть больше 0',
            'dealership_id.exists' => 'Автосалон с таким ID не найден',
            'dealership_id.integer' => 'ID автосалона должен быть числом',
            'dealership_id.min' => 'ID автосалона должен быть больше 0',
            'from_date.date_format' => 'Неверный формат даты "от". Требуется формат: YYYY-MM-DD',
            'to_date.date_format' => 'Неверный формат даты "до". Требуется формат: YYYY-MM-DD',
            'to_date.after_or_equal' => 'Дата "до" должна быть позже или равна дате "от"',
            'record_id.integer' => 'ID записи должен быть числом',
            'record_id.min' => 'ID записи должен быть больше 0',
            'page.integer' => 'Номер страницы должен быть числом',
            'page.min' => 'Номер страницы должен быть больше 0',
            'per_page.integer' => 'Количество записей на странице должно быть числом',
            'per_page.min' => 'Количество записей на странице должно быть больше 0',
            'per_page.max' => 'Максимальное количество записей на странице: 100',
        ];
    }
}
