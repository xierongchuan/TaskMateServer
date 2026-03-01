<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Role;
use App\Exceptions\AccessDeniedException;
use App\Exceptions\SelfEditRestrictedException;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Сервис для управления пользователями.
 *
 * Инкапсулирует бизнес-логику создания, обновления и удаления пользователей.
 * Авторизационные проверки делегируются в UserPolicy (через контроллер).
 * Здесь — только бизнес-правила: проверка пароля, ограничения self-edit,
 * проверка повышения роли, доступ к дилерствам.
 */
class UserService
{
    public function __construct(
        private readonly DealershipAccessService $dealershipAccess,
    ) {}

    /**
     * Создаёт нового пользователя.
     *
     * @param  array<string, mixed>  $data  Валидированные данные из StoreUserRequest
     * @param  User  $creator  Пользователь, создающий нового пользователя
     *
     * @throws AccessDeniedException Если создатель пытается создать Owner-пользователя без прав
     * @throws AccessDeniedException Если дилерство недоступно создателю
     */
    public function createUser(array $data, User $creator): User
    {
        // Только Owner может создавать других Owner
        if ($data['role'] === Role::OWNER->value && ! $this->dealershipAccess->isOwner($creator)) {
            throw new AccessDeniedException('Только Владелец может создавать пользователей с ролью Владельца');
        }

        // Дилерство должно быть доступно создателю
        if (! empty($data['dealership_id'])) {
            $this->dealershipAccess->validateAccess($creator, (int) $data['dealership_id']);
        }

        // Все дилерства из массива должны быть доступны создателю
        if (! empty($data['dealership_ids'])) {
            $this->dealershipAccess->validateMultipleAccess($creator, $data['dealership_ids']);
        }

        $user = User::create([
            'login' => $data['login'],
            'password' => Hash::make($data['password']),
            'full_name' => $data['full_name'],
            'phone' => $data['phone'],
            'role' => $data['role'],
            'dealership_id' => $data['dealership_id'] ?? null,
        ]);

        if (! empty($data['dealership_ids'])) {
            $user->dealerships()->sync($data['dealership_ids']);
        }

        return $user;
    }

    /**
     * Обновляет данные пользователя.
     *
     * @param  User  $targetUser  Пользователь, которого обновляем
     * @param  array<string, mixed>  $data  Валидированные данные из UpdateUserRequest
     * @param  User  $updater  Пользователь, выполняющий обновление
     *
     * @throws SelfEditRestrictedException Если пользователь пытается изменить собственный логин/роль/дилерство
     * @throws AccessDeniedException Если пытается назначить роль Owner без прав
     * @throws AccessDeniedException Если дилерство недоступно
     * @throws \InvalidArgumentException Если текущий пароль указан неверно (при self-update)
     */
    public function updateUser(User $targetUser, array $data, User $updater): User
    {
        $isSelfUpdate = $targetUser->id === $updater->id;

        // Self-edit: нельзя изменять login, role, dealership_id, dealership_ids
        if ($isSelfUpdate) {
            $restrictedFields = ['login', 'role', 'dealership_id', 'dealership_ids'];
            $attemptedChanges = array_intersect_key($data, array_flip($restrictedFields));

            if (! empty($attemptedChanges)) {
                throw new SelfEditRestrictedException('Вы не можете изменять логин, роль или автосалон своего аккаунта');
            }
        }

        // Нельзя назначить роль Owner без прав Owner
        if (isset($data['role']) && $data['role'] === Role::OWNER->value) {
            if (! $this->dealershipAccess->isOwner($updater)) {
                throw new AccessDeniedException('Только Владелец может назначать роль Владельца');
            }
        }

        // Дилерство должно быть доступно updater-у
        if (isset($data['dealership_id'])) {
            $this->dealershipAccess->validateAccess($updater, (int) $data['dealership_id']);
        }

        // Все дилерства из массива должны быть доступны updater-у
        if (isset($data['dealership_ids'])) {
            $this->dealershipAccess->validateMultipleAccess($updater, $data['dealership_ids']);
        }

        $updateData = $this->buildUpdateData($targetUser, $data, $isSelfUpdate);

        // Синхронизация дилерств (через pivot-таблицу)
        if (isset($data['dealership_ids'])) {
            $targetUser->dealerships()->sync($data['dealership_ids']);
        }

        $targetUser->update($updateData);

        return $targetUser;
    }

    /**
     * Удаляет пользователя.
     *
     * Предварительно проверяет наличие связанных данных.
     * Возвращает массив с информацией о связях, если удаление невозможно.
     *
     * @param  User  $targetUser  Пользователь, которого удаляем
     * @param  User  $deleter  Пользователь, выполняющий удаление
     * @return array<string, int> Пустой массив если удаление успешно.
     *                            Массив ['relation' => count, ...] если есть связанные данные.
     */
    public function deleteUser(User $targetUser, User $deleter): array
    {
        // Загружаем счётчики связанных данных одним запросом
        $targetUser->loadCount(['shifts', 'taskAssignments', 'taskResponses', 'createdTasks', 'createdLinks']);

        $countMap = [
            'shifts' => $targetUser->shifts_count,
            'task_assignments' => $targetUser->task_assignments_count,
            'task_responses' => $targetUser->task_responses_count,
            'created_tasks' => $targetUser->created_tasks_count,
            'created_links' => $targetUser->created_links_count,
        ];

        $relatedData = array_filter($countMap, fn (int $count) => $count > 0);

        if (! empty($relatedData)) {
            return $relatedData;
        }

        // Удаляем токены перед удалением пользователя
        $targetUser->tokens()->delete();
        $targetUser->delete();

        return [];
    }

    /**
     * Собирает массив полей для обновления из валидированных данных.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException Если текущий пароль при self-update не совпадает
     */
    private function buildUpdateData(User $targetUser, array $data, bool $isSelfUpdate): array
    {
        $updateData = [];

        // Обновление пароля
        if (! empty($data['password'])) {
            // При self-update требуется проверка текущего пароля
            if ($isSelfUpdate) {
                if (empty($data['current_password']) || ! Hash::check($data['current_password'], $targetUser->password)) {
                    throw new \InvalidArgumentException('Текущий пароль указан неверно');
                }
            }
            $updateData['password'] = Hash::make($data['password']);
        }

        if (isset($data['full_name'])) {
            $updateData['full_name'] = $data['full_name'];
        }

        // Поддержка обоих полей: phone и phone_number
        if (isset($data['phone'])) {
            $updateData['phone'] = $data['phone'];
        } elseif (isset($data['phone_number'])) {
            $updateData['phone'] = $data['phone_number'];
        }

        if (isset($data['role'])) {
            $updateData['role'] = $data['role'];
        }

        if (isset($data['dealership_id'])) {
            $updateData['dealership_id'] = $data['dealership_id'];
        }

        return $updateData;
    }
}
