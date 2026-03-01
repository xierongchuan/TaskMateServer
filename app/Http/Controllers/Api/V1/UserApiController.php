<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\AccessDeniedException;
use App\Exceptions\SelfEditRestrictedException;
use App\Helpers\TimeHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreUserRequest;
use App\Http\Requests\Api\V1\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\EmployeeStatsService;
use App\Services\UserService;
use App\Traits\ApiResponses;
use App\Traits\HasDealershipAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Контроллер для управления пользователями.
 *
 * Предоставляет CRUD операции для пользователей системы.
 * Авторизация — через UserPolicy, бизнес-логика — через UserService.
 */
class UserApiController extends Controller
{
    use ApiResponses, HasDealershipAccess;

    public function __construct(
        private readonly EmployeeStatsService $statsService,
        private readonly UserService $userService,
    ) {}

    /**
     * Получает список пользователей с фильтрацией и пагинацией.
     *
     * @param  Request  $request  HTTP-запрос с параметрами фильтрации
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function index(Request $request)
    {
        $perPage = min((int) $request->query('per_page', '15'), 100);

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
            $escapedSearch = str_replace(['%', '_'], ['\%', '\_'], $search);
            $query->where(function ($q) use ($escapedSearch) {
                $q->where('login', 'ILIKE', "%{$escapedSearch}%")
                    ->orWhere('full_name', 'ILIKE', "%{$escapedSearch}%")
                    ->orWhere('phone', 'ILIKE', "%{$escapedSearch}%");
            });
        }

        // Exact filters
        if ($login !== '') {
            $query->where('login', $login);
        }

        if ($name !== '') {
            $escapedName = str_replace(['%', '_'], ['\%', '\_'], $name);
            $query->where('full_name', 'LIKE', "%{$escapedName}%");
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

        if (! in_array($sortField, $allowedSortFields, true)) {
            $sortField = 'created_at';
        }
        if (! in_array($sortDir, ['asc', 'desc'], true)) {
            $sortDir = 'desc';
        }

        $users = $query->orderBy($sortField, $sortDir)->paginate($perPage);

        $usersData = $users->getCollection()->map(fn ($user) => UserResource::make($user)->resolve());

        return response()->json([
            'success' => true,
            'data' => $usersData,
            'current_page' => $users->currentPage(),
            'last_page' => $users->lastPage(),
            'per_page' => $users->perPage(),
            'total' => $users->total(),
            'links' => [
                'first' => $users->url(1),
                'last' => $users->url($users->lastPage()),
                'prev' => $users->previousPageUrl(),
                'next' => $users->nextPageUrl(),
            ],
        ]);
    }

    /**
     * Получает информацию о конкретном пользователе.
     *
     * @param  Request  $request  HTTP-запрос
     * @param  int|string  $id  ID пользователя
     * @return UserResource|JsonResponse
     */
    public function show(Request $request, $id)
    {
        /** @var User $currentUser */
        $currentUser = $request->user();
        $user = User::find($id);

        if (! $user) {
            return $this->errorResponse('Пользователь не найден', 404);
        }

        // Eager load dealerships для предотвращения lazy load в hasAccessToUser
        $user->loadMissing('dealerships');

        // Проверка доступа к пользователю через общие дилерства
        if (! $this->hasAccessToUser($currentUser, $user)) {
            return $this->errorResponse('Пользователь не найден', 404);
        }

        return $this->successResponse(UserResource::make($user)->resolve());
    }

    /**
     * Проверяет статус активности пользователя.
     *
     * @param  Request  $request  HTTP-запрос
     * @param  int|string  $id  ID пользователя
     * @return JsonResponse
     */
    public function status(Request $request, $id)
    {
        /** @var User $currentUser */
        $currentUser = $request->user();
        $user = User::find($id);

        // Eager load dealerships для предотвращения lazy load в hasAccessToUser
        if ($user) {
            $user->loadMissing('dealerships');
        }

        // Проверка доступа к пользователю через общие дилерства
        if ($user && ! $this->hasAccessToUser($currentUser, $user)) {
            $user = null; // Делаем пользователя невидимым
        }

        // Если пользователь не найден или поле active = false → возвращаем is_active = false
        $isActive = $user && ($user->status == 'active');

        return response()->json([
            'success' => true,
            'data' => ['is_active' => (bool) $isActive],
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
            return $this->errorResponse('Пользователь не найден', 404);
        }

        // Eager load dealerships для предотвращения lazy load в hasAccessToUser
        $user->loadMissing('dealerships');

        if (! $this->hasAccessToUser($currentUser, $user)) {
            return $this->errorResponse('Пользователь не найден', 404);
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

        return $this->successResponse($this->statsService->getStats($user, $from, $to));
    }

    /**
     * Создаёт нового пользователя.
     *
     * @param  StoreUserRequest  $request  Валидированный запрос
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        try {
            $this->authorize('create', User::class);
        } catch (AuthorizationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_type' => 'access_denied',
            ], 403);
        }

        /** @var User $currentUser */
        $currentUser = $request->user();

        try {
            $user = $this->userService->createUser($request->validated(), $currentUser);
        } catch (AccessDeniedException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_type' => 'access_denied',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'message' => 'Сотрудник успешно создан',
            'data' => new UserResource($user),
        ], 201);
    }

    /**
     * Обновляет данные пользователя.
     *
     * @param  UpdateUserRequest  $request  Валидированный запрос
     * @param  int|string  $id  ID пользователя
     */
    public function update(UpdateUserRequest $request, $id): JsonResponse
    {
        $targetUser = User::with('dealerships')->findOrFail($id);

        try {
            $this->authorize('update', $targetUser);
        } catch (AuthorizationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_type' => 'access_denied',
            ], 403);
        }

        /** @var User $currentUser */
        $currentUser = $request->user();

        try {
            $updatedUser = $this->userService->updateUser($targetUser, $request->validated(), $currentUser);
        } catch (SelfEditRestrictedException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_type' => 'self_edit_restricted',
            ], 403);
        } catch (AccessDeniedException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_type' => 'access_denied',
            ], 403);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => ['current_password' => ['Неверный текущий пароль']],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Данные пользователя успешно обновлены',
            'data' => new UserResource($updatedUser),
        ], 200);
    }

    /**
     * Удаляет пользователя.
     *
     * @param  Request  $request  HTTP-запрос
     * @param  int|string  $id  ID пользователя
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        $targetUser = User::with('dealerships')->findOrFail($id);

        try {
            $this->authorize('delete', $targetUser);
        } catch (AuthorizationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 403);
        }

        /** @var User $currentUser */
        $currentUser = $request->user();

        // Сохраняем имя до удаления — после soft-delete атрибут всё равно доступен,
        // но фиксируем его явно для читаемости
        $userName = $targetUser->full_name;

        try {
            $relatedData = $this->userService->deleteUser($targetUser, $currentUser);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при удалении пользователя',
                'error' => config('app.debug') ? $e->getMessage() : 'Внутренняя ошибка сервера',
            ], 500);
        }

        if (! empty($relatedData)) {
            return response()->json([
                'success' => false,
                'message' => 'Невозможно удалить пользователя со связанными данными',
                'related_data' => $relatedData,
                'errors' => [
                    'user' => ['Пользователь имеет связанные записи: '.implode(', ', array_keys($relatedData))],
                ],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => "Пользователь '{$userName}' успешно удален",
        ], 200);
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
