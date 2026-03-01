<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\Task;
use App\Rules\ValidAssignmentsForTaskType;
use Illuminate\Contracts\Validation\Validator;

/**
 * Form Request для обновления задачи.
 */
class UpdateTaskRequest extends BaseApiRequest
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

            // Валидация типа задачи и количества исполнителей через переиспользуемое правило
            $taskType = $this->input('task_type') ?? $task?->task_type;

            if ($taskType) {
                $assignments = $this->input('assignments');

                // Если assignments не переданы — передаём текущее количество из БД в правило
                $currentCount = ($assignments === null && $task)
                    ? $task->assignments()->count()
                    : 0;

                $rule = new ValidAssignmentsForTaskType($taskType, $currentCount);
                $rule->validate('assignments', $assignments, function (string $message) use ($validator) {
                    $attribute = str_contains($message, 'Индивидуальная') ? 'task_type' : 'assignments';
                    $validator->errors()->add($attribute, $message);
                });
            }
        });
    }
}
