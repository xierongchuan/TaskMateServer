<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\TimeHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreUserRequest;
use App\Http\Requests\Api\V1\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\EmployeeStatsService;
use App\Traits\HasDealershipAccess;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Enums\Role;
use Illuminate\Support\Facades\Hash;

/**
 * Контроллер для управления пользователями.
 *
 * Предоставляет CRUD операции для пользователей системы.
 */
class UserApiController extends Controller
{
    use HasDealershipAccess;

    /**
     * Получает список пользователей с фильтрацией и пагинацией.
     *
     * @param Request $request HTTP-запрос с параметрами фильтрации
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function index(Request $request)
    {
        $perPage = (int) $request->query('per_page', '15');

        // Get filter parameters
        $search = (string) $request->query('search', '');
        $login = (string) $request->query('login', '');
        $name = (string) $request->query('name', '');
        $role = (string) $request->query('role', '');

        $phone = (string) $request->query('phone', '');

        // Support both 'phone' and 'phone_number' parameters
        if ($phone === '') {
            $phone = (string) $request->query('phone_number', '');
        }

        $query = User::query();

        // Search by login or name (OR logic)
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('login', 'ILIKE', "%{$search}%")
                  ->orWhere('full_name', 'ILIKE', "%{$search}%")
                  ->orWhere('phone', 'ILIKE', "%{$search}%");
            });
        }

        // Exact filters
        if ($login !== '') {
            $query->where('login', $login);
        }

        if ($name !== '') {
            $query->where('full_name', 'LIKE', "%{$name}%");
        }

        if ($role !== '') {
            $query->where('role', $role);
        }

        // Фильтрация по автосалону (приоритет над orphan_only)
        if ($request->filled('dealership_id')) {
            $dealershipId = $request->input('dealership_id');

            $query->where(function ($q) use ($dealershipId) {
                // Change 'dealership_id' to 'users.dealership_id' to avoid any ambiguity
                $q->where('users.dealership_id', $dealershipId)
                  ->orWhereHas('dealerships', function ($subQ) use ($dealershipId) {
                      $subQ->where('auto_dealerships.id', $dealershipId);
                  });
            });
        } elseif ($request->filled('orphan_only') && in_array($request->query('orphan_only'), ['true', '1'], true)) {
            // Режим "orphan users" - пользователи без привязки к автосалонам
            $query->whereNull('users.dealership_id')
                  ->whereDoesntHave('dealerships');
        }

        // Phone filtering with normalization (existing logic)
        if ($phone !== '') {
            $normalized = $this->normalizePhone($phone);

            // Если после нормализации пусто — возвращаем пустую страницу
            if ($normalized === '') {
                return UserResource::collection(collect([]));
            }

            // Определяем драйвер БД
            $driver = config('database.default');

            if ($driver === 'pgsql') {
                $query->whereRaw("regexp_replace(phone, '[^0-9]', '', 'g') LIKE ?", ["%{$normalized}%"]);
            } elseif ($driver === 'mysql') {
                $query->whereRaw("REGEXP_REPLACE(phone, '[^0-9]', '') LIKE ?", ["%{$normalized}%"]);
            } else {
                $query->whereRaw(
                    "REPLACE(REPLACE(REPLACE(phone, ' ', ''), '+', ''), '-', '') LIKE ?",
                    ["%{$normalized}%"]
                );
            }
        }

        // Always eager load dealership and dealerships relationships
        $query->with(['dealership', 'dealerships']);

        // Scope by accessible dealerships for non-owners
        /** @var User $currentUser */
        $currentUser = $request->user();
        $this->scopeUsersByAccessibleDealerships($query, $currentUser);

        $allowedSortFields = ['created_at', 'full_name'];
        $sortField = $request->get('sort_by', 'created_at');
        $sortDir = $request->get('sort_dir', 'desc');

        if (!in_array($sortField, $allowedSortFields, true)) {
            $sortField = 'created_at';
        }
        if (!in_array($sortDir, ['asc', 'desc'], true)) {
            $sortDir = 'desc';
        }

        $users = $query->orderBy($sortField, $sortDir)->paginate($perPage);
        return UserResource::collection($users);
    }

    /**
     * Получает информацию о конкретном пользователе.
     *
     * @param Request $request HTTP-запрос
     * @param int|string $id ID пользователя
     * @return UserResource|JsonResponse
     */
    public function show(Request $request, $id)
    {
        /** @var User $currentUser */
        $currentUser = $request->user();
        $user = User::find($id);

        if (! $user) {
            return response()->json([
                'message' => 'Пользователь не найден'
            ], 404);
        }

        // Проверка доступа к пользователю через общие дилерства
        if (!$this->hasAccessToUser($currentUser, $user)) {
            return response()->json([
                'message' => 'Пользователь не найден'
            ], 404);
        }

        return new UserResource($user);
    }

    /**
     * Проверяет статус активности пользователя.
     *
     * @param Request $request HTTP-запрос
     * @param int|string $id ID пользователя
     * @return JsonResponse
     */
    public function status(Request $request, $id)
    {
        /** @var User $currentUser */
        $currentUser = $request->user();
        $user = User::find($id);

        // Проверка доступа к пользователю через общие дилерства
        if ($user && !$this->hasAccessToUser($currentUser, $user)) {
            $user = null; // Делаем пользователя невидимым
        }

        // Если пользователь не найден или поле active = false → возвращаем is_active = false
        $isActive = $user && ($user->status == 'active');

        return response()->json([
            'is_active' => (bool) $isActive,
        ]);
    }

    /**
     * Возвращает подробную статистику пользователя за период.
     */
    public function stats(Request $request, $id): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $request->user();
        $user = User::find($id);

        if (! $user) {
            return response()->json(['message' => 'Пользователь не найден'], 404);
        }

        if (! $this->hasAccessToUser($currentUser, $user)) {
            return response()->json(['message' => 'Пользователь не найден'], 404);
        }

        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');

        // По умолчанию — последние 30 дней
        $from = $dateFrom
            ? TimeHelper::startOfDayUtc($dateFrom)
            : TimeHelper::nowUtc()->subDays(30)->startOfDay();
        $to = $dateTo
            ? TimeHelper::endOfDayUtc($dateTo)
            : TimeHelper::endOfDayUtc(TimeHelper::nowUtc()->format('Y-m-d'));

        $statsService = app(EmployeeStatsService::class);

        return response()->json($statsService->getStats($user, $from, $to));
    }

    /**
     * Обновляет данные пользователя.
     *
     * @param UpdateUserRequest $request Валидированный запрос
     * @param int|string $id ID пользователя
     * @return JsonResponse
     */
    public function update(UpdateUserRequest $request, $id): JsonResponse
    {
        $user = User::with('dealerships')->find($id);

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Пользователь не найден'
            ], 404);
        }

        /** @var User $currentUser */
        $currentUser = $request->user();

        // Security check: Non-owners cannot modify Owners
        if (!$this->isOwner($currentUser) && $user->role === Role::OWNER) {
            return response()->json([
                'success' => false,
                'message' => 'У вас нет прав для редактирования Владельца',
                'error_type' => 'access_denied'
            ], 403);
        }

        // Security check: Non-owners cannot modify other Managers (but can edit themselves)
        if (!$this->isOwner($currentUser) && $user->role === Role::MANAGER && $user->id !== $currentUser->id) {
            return response()->json([
                'success' => false,
                'message' => 'У вас нет прав для редактирования Управляющего',
                'error_type' => 'access_denied'
            ], 403);
        }

        $validated = $request->validated();

        // Security check: Restrict self-editing - users cannot change their own critical fields
        // This check MUST be first so it catches attempts before other validations
        if ($user->id === $currentUser->id) {
            $restrictedFields = ['login', 'role', 'dealership_id', 'dealership_ids'];
            $attemptedChanges = array_intersect_key($validated, array_flip($restrictedFields));

            if (!empty($attemptedChanges)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Вы не можете изменять логин, роль или автосалон своего аккаунта',
                    'error_type' => 'self_edit_restricted'
                ], 403);
            }
        }

        // Security check: Scope access to assigned dealerships (skip for self-editing)
        if ($user->id !== $currentUser->id) {
            $accessError = $this->validateUserAccess($currentUser, $user);
            if ($accessError) {
                return response()->json([
                    'success' => false,
                    'message' => 'У вас нет прав для редактирования сотрудника другого автосалона',
                    'error_type' => 'access_denied'
                ], 403);
            }
        }

        // Security check: Non-owners cannot promote users to Owner
        if (isset($validated['role']) && $validated['role'] === Role::OWNER->value) {
            if (!$this->isOwner($currentUser)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Только Владелец может назначать роль Владельца',
                    'error_type' => 'access_denied'
                ], 403);
            }
        }

        // Security check: Ensure new dealership is accessible
        if (isset($validated['dealership_id'])) {
            $accessError = $this->validateDealershipAccess($currentUser, (int) $validated['dealership_id']);
            if ($accessError) {
                return response()->json([
                    'success' => false,
                    'message' => 'Вы не можете привязать сотрудника к чужому автосалону',
                    'error_type' => 'access_denied'
                ], 403);
            }
        }

        // Security check: Ensure new dealerships array is accessible
        if (isset($validated['dealership_ids'])) {
            $accessError = $this->validateMultipleDealershipsAccess($currentUser, $validated['dealership_ids']);
            if ($accessError) {
                return response()->json([
                    'success' => false,
                    'message' => 'Вы не можете управлять доступом к чужому автосалону',
                    'error_type' => 'access_denied'
                ], 403);
            }
        }

        $updateData = [];

        // Only update password if it's provided and not empty
        if (isset($validated['password']) && $validated['password'] !== '' && $validated['password'] !== null) {
            // Security: If user is changing their OWN password, require current_password verification
            // Owners/Managers can reset others' passwords without this check
            if ($user->id === $currentUser->id) {
                if (!isset($validated['current_password']) || !Hash::check($validated['current_password'], $user->password)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Текущий пароль указан неверно',
                        'errors' => ['current_password' => ['Неверный текущий пароль']]
                    ], 422);
                }
            }
            $updateData['password'] = Hash::make($validated['password']);
        }

        if (isset($validated['full_name'])) {
            $updateData['full_name'] = $validated['full_name'];
        }

        // Support both 'phone' and 'phone_number' fields
        if (isset($validated['phone'])) {
            $updateData['phone'] = $validated['phone'];
        } elseif (isset($validated['phone_number'])) {
            $updateData['phone'] = $validated['phone_number'];
        }

        if (isset($validated['role'])) {
            $updateData['role'] = $validated['role'];
        }

        if (isset($validated['dealership_id'])) {
            $updateData['dealership_id'] = $validated['dealership_id'];
        }

        if (isset($validated['dealership_ids'])) {
            $user->dealerships()->sync($validated['dealership_ids']);
        }

        $user->update($updateData);

        return response()->json([
            'success' => true,
            'message' => 'Данные пользователя успешно обновлены',
            'data' => new UserResource($user)
        ], 200);
    }

    /**
     * Создаёт нового пользователя.
     *
     * @param StoreUserRequest $request Валидированный запрос
     * @return JsonResponse
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $validated = $request->validated();

        /** @var User $currentUser */
        $currentUser = $request->user();

        // Security check: Non-owners cannot create Owners
        if ($validated['role'] === Role::OWNER->value) {
            if (!$this->isOwner($currentUser)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Только Владелец может создавать пользователей с ролью Владельца',
                    'error_type' => 'access_denied'
                ], 403);
            }
        }

        // Security check: Ensure new dealership is accessible
        if (!empty($validated['dealership_id'])) {
            $accessError = $this->validateDealershipAccess($currentUser, (int) $validated['dealership_id']);
            if ($accessError) {
                return response()->json([
                    'success' => false,
                    'message' => 'Вы не можете создать сотрудника в чужом автосалоне',
                    'error_type' => 'access_denied'
                ], 403);
            }
        }

        // Security check: Ensure new dealerships array is accessible
        if (!empty($validated['dealership_ids'])) {
            $accessError = $this->validateMultipleDealershipsAccess($currentUser, $validated['dealership_ids']);
            if ($accessError) {
                return response()->json([
                    'success' => false,
                    'message' => 'Вы не можете дать доступ к чужому автосалону',
                    'error_type' => 'access_denied'
                ], 403);
            }
        }

        $user = User::create([
            'login' => $validated['login'],
            'password' => Hash::make($validated['password']),
            'full_name' => $validated['full_name'],
            'phone' => $validated['phone'],
            'role' => $validated['role'],
            'dealership_id' => $validated['dealership_id'] ?? null,
        ]);

        if (!empty($validated['dealership_ids'])) {
            $user->dealerships()->sync($validated['dealership_ids']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Сотрудник успешно создан',
            'data' => new UserResource($user)
        ], 201);
    }

    /**
     * Удаляет пользователя.
     *
     * @param int|string $id ID пользователя
     * @return JsonResponse
     */
    public function destroy($id): JsonResponse
    {
        $user = User::with('dealerships')->find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Пользователь не найден'
            ], 404);
        }

        /** @var User $currentUser */
        $currentUser = request()->user();

        // Security check: Users cannot delete themselves
        if ($user->id === $currentUser->id) {
            return response()->json([
                'success' => false,
                'message' => 'Вы не можете удалить свой собственный аккаунт'
            ], 403);
        }

        // Security check: Only Owner can delete Owner
        if ($user->role === Role::OWNER && !$this->isOwner($currentUser)) {
            return response()->json([
                'success' => false,
                'message' => 'У вас нет прав для удаления Владельца'
            ], 403);
        }

        // Security check: Non-owners cannot delete managers
        if (!$this->isOwner($currentUser) && $user->role === Role::MANAGER) {
            return response()->json([
                'success' => false,
                'message' => 'У вас нет прав для удаления Управляющего'
            ], 403);
        }

        // Security check: Scope access to assigned dealerships for deletion
        $accessError = $this->validateUserAccess($currentUser, $user);
        if ($accessError) {
            return response()->json([
                'success' => false,
                'message' => 'У вас нет прав для удаления сотрудника другого автосалона'
            ], 403);
        }

        // Проверяем наличие связанных данных
        $relatedData = [];

        if ($user->shifts()->count() > 0) {
            $relatedData['shifts'] = $user->shifts()->count();
        }

        if ($user->taskAssignments()->count() > 0) {
            $relatedData['task_assignments'] = $user->taskAssignments()->count();
        }

        if ($user->taskResponses()->count() > 0) {
            $relatedData['task_responses'] = $user->taskResponses()->count();
        }

        if ($user->createdTasks()->count() > 0) {
            $relatedData['created_tasks'] = $user->createdTasks()->count();
        }

        if ($user->createdLinks()->count() > 0) {
            $relatedData['created_links'] = $user->createdLinks()->count();
        }

        if (!empty($relatedData)) {
            return response()->json([
                'success' => false,
                'message' => 'Невозможно удалить пользователя со связанными данными',
                'related_data' => $relatedData,
                'errors' => [
                    'user' => ['Пользователь имеет связанные записи: ' . implode(', ', array_keys($relatedData))]
                ]
            ], 422);
        }

        try {
            // Удаляем все токены пользователя перед удалением
            $user->tokens()->delete();

            $userName = $user->full_name;
            $user->delete();

            return response()->json([
                'success' => true,
                'message' => "Пользователь '{$userName}' успешно удален"
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при удалении пользователя',
                'error' => config('app.debug') ? $e->getMessage() : 'Внутренняя ошибка сервера'
            ], 500);
        }
    }

    /**
     * Нормализует телефон: убирает все не-цифры.
     * Возвращает строку из цифр или пустую строку.
     */
    private function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }
}
