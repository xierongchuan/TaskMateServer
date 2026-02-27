<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Form Request для обновления задачи.
 */
class UpdateTaskRequest extends FormRequest
{
    /**
     * Определяет, авторизован ли пользователь для этого запроса.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Правила валидации для обновления задачи.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'comment' => 'nullable|string',
            'dealership_id' => 'nullable|exists:auto_dealerships,id',
            'appear_date' => 'sometimes|required|string',
            'deadline' => 'sometimes|required|string',
            'task_type' => 'sometimes|required|string|in:individual,group',
            'response_type' => 'sometimes|required|string|in:notification,completion,completion_with_proof',
            'tags' => 'nullable|array',
            'is_active' => 'boolean',
            'assignments' => 'nullable|array',
            'assignments.*' => 'exists:users,id',
            'notification_settings' => 'nullable|array',
            'priority' => 'nullable|string|in:low,medium,high',
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
            'title.required' => 'Название задачи обязательно',
            'title.max' => 'Название задачи не может превышать 255 символов',
            'dealership_id.exists' => 'Автосалон не найден',
            'appear_date.required' => 'Дата появления задачи обязательна',
            'deadline.required' => 'Дедлайн обязателен',
            'task_type.required' => 'Тип задачи обязателен',
            'task_type.in' => 'Некорректный тип задачи',
            'response_type.required' => 'Тип ответа обязателен',
            'response_type.in' => 'Некорректный тип ответа. Допустимы: notification, completion, completion_with_proof',
            'assignments.*.exists' => 'Пользователь не найден',
            'priority.in' => 'Некорректный приоритет',
        ];
    }

    /**
     * Дополнительная валидация после прохождения базовых правил.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            // Получаем текущую задачу для проверки полей
            $task = $this->route('task') instanceof Task
                ? $this->route('task')
                : Task::find($this->route('task'));

            // Валидация типа задачи и количества исполнителей
            $taskType = $this->input('task_type') ?? $task?->task_type;
            $assignments = $this->input('assignments');

            // Если assignments не передан в запросе, берём текущие из БД
            if ($assignments === null && $task) {
                $assignmentCount = $task->assignments()->count();
            } else {
                $assignmentCount = is_array($assignments) ? count($assignments) : 0;
            }

            // Групповая задача должна иметь хотя бы одного исполнителя
            if ($taskType === 'group' && $assignmentCount === 0) {
                $validator->errors()->add(
                    'assignments',
                    'Для групповой задачи необходимо указать хотя бы одного исполнителя'
                );
            }

            // Индивидуальная задача не может иметь более одного исполнителя
            if ($taskType === 'individual' && $assignmentCount > 1) {
                $validator->errors()->add(
                    'task_type',
                    'Индивидуальная задача не может иметь более одного исполнителя. Используйте групповую задачу для нескольких исполнителей.'
                );
            }
        });
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
