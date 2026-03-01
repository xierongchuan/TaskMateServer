<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Events\DelegationAccepted;
use App\Events\DelegationRejected;
use App\Events\DelegationRequested;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreDelegationRequest;
use App\Http\Resources\TaskDelegationResource;
use App\Models\Task;
use App\Models\TaskDelegation;
use App\Models\User;
use App\Services\TaskDelegationService;
use App\Traits\ApiResponses;
use App\Traits\HasDealershipAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Контроллер для управления делегированием задач.
 */
class TaskDelegationController extends Controller
{
    use ApiResponses, HasDealershipAccess;

    public function __construct(
        private readonly TaskDelegationService $delegationService,
    ) {}

    /**
     * Создать запрос на делегирование задачи.
     *
     * POST /api/v1/tasks/{task}/delegations
     */
    public function store(StoreDelegationRequest $request, Task $task): JsonResponse
    {
        $fromUser = $request->user();
        $toUser = User::findOrFail($request->validated('to_user_id'));

        try {
            $delegation = $this->delegationService->createDelegation(
                $task,
                $fromUser,
                $toUser,
                $request->validated('reason'),
            );
        } catch (\DomainException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }

        event(new DelegationRequested($delegation));

        return $this->createdResponse(
            TaskDelegationResource::make($delegation->load(['fromUser', 'toUser', 'task']))->resolve(),
            'Запрос на делегирование создан'
        );
    }

    /**
     * Список делегаций.
     *
     * GET /api/v1/task-delegations
     *
     * Employee видит только свои (in/out).
     * Manager/Owner видит все в доступных dealership.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $query = TaskDelegation::with(['fromUser', 'toUser', 'task']);

        // Сотрудники видят только свои делегации
        if ($user->role === Role::EMPLOYEE) {
            $query->where(function ($q) use ($user) {
                $q->where('from_user_id', $user->id)
                    ->orWhere('to_user_id', $user->id);
            });
        } elseif (! $this->isOwner($user)) {
            // Менеджеры видят все делегации в своих dealership
            $accessibleIds = $this->getAccessibleDealershipIds($user);
            $query->whereHas('task', function ($q) use ($accessibleIds) {
                $q->whereIn('dealership_id', $accessibleIds);
            });
        }

        // Фильтры
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('direction')) {
            $direction = $request->input('direction');
            if ($direction === 'incoming') {
                $query->where('to_user_id', $user->id);
            } elseif ($direction === 'outgoing') {
                $query->where('from_user_id', $user->id);
            }
        }

        if ($request->filled('task_id')) {
            $query->where('task_id', $request->input('task_id'));
        }

        $query->orderByDesc('created_at');
        $perPage = min((int) $request->input('per_page', 15), 50);
        $delegations = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => TaskDelegationResource::collection($delegations->getCollection()),
            'current_page' => $delegations->currentPage(),
            'last_page' => $delegations->lastPage(),
            'per_page' => $delegations->perPage(),
            'total' => $delegations->total(),
            'links' => [
                'first' => $delegations->url(1),
                'last' => $delegations->url($delegations->lastPage()),
                'prev' => $delegations->previousPageUrl(),
                'next' => $delegations->nextPageUrl(),
            ],
        ]);
    }

    /**
     * Получить одну делегацию.
     *
     * GET /api/v1/task-delegations/{id}
     */
    public function show(int $id): JsonResponse
    {
        $delegation = TaskDelegation::with(['fromUser', 'toUser', 'task'])->findOrFail($id);

        /** @var \App\Models\User $user */
        $user = auth()->user();

        if (! $this->canViewDelegation($user, $delegation)) {
            return $this->forbiddenResponse('Нет доступа');
        }

        return $this->successResponse(TaskDelegationResource::make($delegation)->resolve());
    }

    /**
     * Принять делегирование.
     *
     * POST /api/v1/task-delegations/{id}/accept
     */
    public function accept(int $id): JsonResponse
    {
        $delegation = TaskDelegation::findOrFail($id);

        /** @var \App\Models\User $user */
        $user = auth()->user();

        // Только адресат может принять
        if ($delegation->to_user_id !== $user->id) {
            return $this->forbiddenResponse('Только адресат может принять запрос');
        }

        try {
            $delegation = $this->delegationService->accept($delegation);
        } catch (\DomainException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }

        event(new DelegationAccepted($delegation));

        return $this->successResponse(
            TaskDelegationResource::make($delegation->load(['fromUser', 'toUser', 'task']))->resolve(),
            'Делегирование принято'
        );
    }

    /**
     * Отклонить делегирование.
     *
     * POST /api/v1/task-delegations/{id}/reject
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        $delegation = TaskDelegation::findOrFail($id);

        /** @var \App\Models\User $user */
        $user = $request->user();

        // Только адресат может отклонить
        if ($delegation->to_user_id !== $user->id) {
            return $this->forbiddenResponse('Только адресат может отклонить запрос');
        }

        try {
            $delegation = $this->delegationService->reject($delegation, $validated['reason']);
        } catch (\DomainException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }

        event(new DelegationRejected($delegation));

        return $this->successResponse(
            TaskDelegationResource::make($delegation->load(['fromUser', 'toUser', 'task']))->resolve(),
            'Делегирование отклонено'
        );
    }

    /**
     * Отменить делегирование.
     *
     * POST /api/v1/task-delegations/{id}/cancel
     *
     * Доступно инициатору, менеджеру или владельцу.
     */
    public function cancel(int $id): JsonResponse
    {
        $delegation = TaskDelegation::findOrFail($id);

        /** @var \App\Models\User $user */
        $user = auth()->user();

        $isInitiator = $delegation->from_user_id === $user->id;
        $isManagerOrOwner = in_array($user->role, [Role::MANAGER, Role::OWNER]);

        if (! $isInitiator && ! $isManagerOrOwner) {
            return $this->forbiddenResponse('Недостаточно прав для отмены');
        }

        // Проверка доступа к dealership для менеджера
        if ($isManagerOrOwner && ! $isInitiator) {
            $task = Task::find($delegation->task_id);
            if ($task && ! $this->isOwner($user) && ! $this->hasAccessToDealership($user, $task->dealership_id)) {
                return $this->forbiddenResponse('Нет доступа к этой задаче');
            }
        }

        try {
            $delegation = $this->delegationService->cancel($delegation, $user);
        } catch (\DomainException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }

        return $this->successResponse(
            TaskDelegationResource::make($delegation->load(['fromUser', 'toUser', 'task']))->resolve(),
            'Запрос на делегирование отменён'
        );
    }

    /**
     * Проверка: может ли пользователь видеть делегацию.
     */
    private function canViewDelegation(User $user, TaskDelegation $delegation): bool
    {
        if ($this->isOwner($user)) {
            return true;
        }

        if ($delegation->from_user_id === $user->id || $delegation->to_user_id === $user->id) {
            return true;
        }

        $task = $delegation->task;
        if ($task && $this->hasAccessToDealership($user, $task->dealership_id)) {
            return true;
        }

        return false;
    }
}
