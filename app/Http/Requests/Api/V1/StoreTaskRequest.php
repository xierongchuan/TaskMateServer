<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\Role;
use App\Models\User;
use App\Rules\ValidAssignmentsForTaskType;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\Validator;

/**
 * Form Request для создания задачи.
 */
class StoreTaskRequest extends BaseApiRequest
{
    /**
     * Определяет, авторизован ли пользователь для этого запроса.
     * Только manager и owner могут создавать задачи.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user) {
            return false;
        }

        return in_array($user->role, [Role::MANAGER, Role::OWNER]);
    }

    /**
     * Правила валидации для создания задачи.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'comment' => 'nullable|string',
            'dealership_id' => 'nullable|exists:auto_dealerships,id',
            'appear_date' => 'required|string',
            'deadline' => 'required|string',
            'task_type' => 'required|string|in:individual,group',
            'response_type' => 'required|string|in:notification,completion,completion_with_proof',
            'tags' => 'nullable|array',
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
            $assignments = $this->input('assignments');
            $assignmentCount = is_array($assignments) ? count($assignments) : 0;

            // Валидация типа задачи и количества исполнителей через переиспользуемое правило
            $taskType = $this->input('task_type');
            if ($taskType) {
                $rule = new ValidAssignmentsForTaskType($taskType);
                $rule->validate('assignments', $assignments, function (string $message) use ($validator) {
                    $attribute = str_contains($message, 'Индивидуальная') ? 'task_type' : 'assignments';
                    $validator->errors()->add($attribute, $message);
                });
            }

            // Валидация: дата появления должна быть раньше или равна дедлайну
            $appearDate = $this->input('appear_date');
            $deadline = $this->input('deadline');

            if ($appearDate && $deadline) {
                try {
                    $appearDateTime = Carbon::parse($appearDate);
                    $deadlineDateTime = Carbon::parse($deadline);

                    if ($appearDateTime->gt($deadlineDateTime)) {
                        $validator->errors()->add(
                            'deadline',
                            'Дедлайн не может быть раньше даты появления задачи'
                        );
                    }
                } catch (\Exception $e) {
                    // Некорректный формат даты - будет обработан базовой валидацией
                }
            }

            // Валидация: нельзя назначить задачу самому себе
            $user = $this->user();
            if ($user && $assignmentCount > 0 && in_array($user->id, $assignments)) {
                $validator->errors()->add(
                    'assignments',
                    'Вы не можете назначить задачу самому себе'
                );
            }

            // Валидация: задачи можно назначать только сотрудникам (employees)
            if ($assignmentCount > 0) {
                $nonEmployees = User::whereIn('id', $assignments)
                    ->where('role', '!=', Role::EMPLOYEE)
                    ->count();

                if ($nonEmployees > 0) {
                    $validator->errors()->add(
                        'assignments',
                        'Задачи можно назначать только сотрудникам'
                    );
                }
            }
        });
    }
}
