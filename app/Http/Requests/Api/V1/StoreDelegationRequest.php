<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;

/**
 * Form Request для создания запроса на делегирование задачи.
 */
class StoreDelegationRequest extends BaseApiRequest
{
    /**
     * Только сотрудники (employee) могут делегировать задачи.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && $user->role === Role::EMPLOYEE;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'to_user_id' => 'required|exists:users,id',
            'reason' => 'nullable|string|max:1000',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'to_user_id.required' => 'Необходимо указать сотрудника для делегирования',
            'to_user_id.exists' => 'Указанный сотрудник не найден',
            'reason.max' => 'Причина делегирования не может превышать 1000 символов',
        ];
    }

    /**
     * Дополнительная валидация бизнес-правил.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $user = $this->user();
            $toUserId = $this->input('to_user_id');

            if (! $user || ! $toUserId) {
                return;
            }

            // Нельзя делегировать самому себе
            if ((int) $toUserId === $user->id) {
                $validator->errors()->add('to_user_id', 'Нельзя делегировать задачу самому себе');

                return;
            }

            $targetUser = User::find($toUserId);
            if (! $targetUser) {
                return;
            }

            // Target должен быть employee
            if ($targetUser->role !== Role::EMPLOYEE) {
                $validator->errors()->add('to_user_id', 'Задачи можно делегировать только сотрудникам');

                return;
            }

            // Target должен иметь доступ к dealership задачи
            $task = $this->route('task');
            if ($task && $task->dealership_id) {
                $targetDealershipIds = $targetUser->getAccessibleDealershipIds();
                if (! in_array($task->dealership_id, $targetDealershipIds)) {
                    $validator->errors()->add('to_user_id', 'Сотрудник должен быть в том же автосалоне');
                }
            }
        });
    }
}
