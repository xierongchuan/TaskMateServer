<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreTaskRequest;
use App\Http\Requests\Api\V1\UpdateTaskRequest;
use App\Http\Requests\Api\V1\UpdateTaskStatusRequest;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Services\TaskFilterService;
use App\Services\TaskService;
use App\Services\TaskStatusService;
use App\Traits\HasDealershipAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    use HasDealershipAccess;

    public function __construct(
        private readonly TaskService $taskService,
        private readonly TaskFilterService $taskFilterService,
        private readonly TaskStatusService $taskStatusService,
    ) {}

    /**
     * Получает список задач с фильтрацией и пагинацией.
     *
     * @param  Request  $request  HTTP-запрос с параметрами фильтрации
     */
    public function index(Request $request): JsonResponse
    {
        /** @var \App\Models\User $currentUser */
        $currentUser = $request->user();

        $tasks = $this->taskFilterService->getFilteredTasks($request, $currentUser);

        $tasksData = $tasks->getCollection()->map(fn ($task) => TaskResource::make($task)->resolve());

        return response()->json([
            'data' => $tasksData,
            'current_page' => $tasks->currentPage(),
            'last_page' => $tasks->lastPage(),
            'per_page' => $tasks->perPage(),
            'total' => $tasks->total(),
            'links' => [
                'first' => $tasks->url(1),
                'last' => $tasks->url($tasks->lastPage()),
                'prev' => $tasks->previousPageUrl(),
                'next' => $tasks->nextPageUrl(),
            ],
        ]);
    }

    /**
     * Получает детальную информацию о задаче.
     *
     * @param  int|string  $id  ID задачи
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $task = Task::with([
            'creator',
            'dealership',
            'assignments.user',
            'responses.user',
            'responses.proofs',
            'responses.verifier',
            'sharedProofs',
            'delegations.fromUser',
            'delegations.toUser',
        ])->findOrFail($id);

        /** @var \App\Models\User $currentUser */
        $currentUser = auth()->user();

        // Security check: Access scope via Policy
        $this->authorize('view', $task);

        return response()->json(TaskResource::make($task)->resolve());
    }

    /**
     * Создаёт новую задачу.
     *
     * @param  StoreTaskRequest  $request  Валидированный запрос
     */
    public function store(StoreTaskRequest $request): JsonResponse
    {
        /** @var \App\Models\User $currentUser */
        $currentUser = $request->user();

        // Security check: Ensure dealership is accessible
        $validated = $request->validated();
        if (! empty($validated['dealership_id'])) {
            if (! $this->taskService->canAccessDealership($currentUser, (int) $validated['dealership_id'])) {
                return response()->json([
                    'message' => 'Вы не можете создать задачу в чужом автосалоне',
                    'error_type' => 'access_denied',
                ], 403);
            }
        }

        $task = $this->taskService->createTask($validated, $currentUser);

        return response()->json(TaskResource::make($task->load(['assignments.user']))->resolve(), 201);
    }

    /**
     * Обновляет существующую задачу.
     *
     * @param  UpdateTaskRequest  $request  Валидированный запрос
     * @param  int|string  $id  ID задачи
     */
    public function update(UpdateTaskRequest $request, $id): JsonResponse
    {
        $task = Task::findOrFail($id);

        /** @var \App\Models\User $currentUser */
        $currentUser = auth()->user();

        // Security check: Access scope via Policy
        $this->authorize('update', $task);

        // Запрет редактирования выполненных задач
        if (in_array($task->status, ['completed', 'completed_late'])) {
            return response()->json([
                'message' => 'Нельзя редактировать выполненную задачу',
            ], 422);
        }

        $validated = $request->validated();

        // Security check: Ensure new dealership is accessible
        if (isset($validated['dealership_id'])) {
            if (! $this->taskService->canAccessDealership($currentUser, (int) $validated['dealership_id'])) {
                return response()->json([
                    'message' => 'Вы не можете перенести задачу в чужой автосалон',
                    'error_type' => 'access_denied',
                ], 403);
            }
        }

        $task = $this->taskService->updateTask($task, $validated);

        return response()->json(TaskResource::make($task->load(['assignments.user', 'responses.user']))->resolve());
    }

    /**
     * Удаляет задачу.
     *
     * @param  int|string  $id  ID задачи
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $task = Task::findOrFail($id);

        /** @var \App\Models\User $currentUser */
        $currentUser = auth()->user();

        // Security check: Access scope via Policy
        $this->authorize('delete', $task);

        // Delete task assignments (they will be automatically deleted due to foreign key constraints)
        TaskAssignment::where('task_id', $task->id)->delete();

        // Delete the task
        $task->delete();

        return response()->json([
            'message' => 'Задача успешно удалена',
        ]);
    }

    /**
     * Обновляет статус задачи.
     *
     * Поддерживает загрузку файлов доказательств для задач типа completion_with_proof.
     *
     * @param  UpdateTaskStatusRequest  $request  Валидированный запрос со статусом и файлами
     * @param  int|string  $id  ID задачи
     */
    public function updateStatus(UpdateTaskStatusRequest $request, $id): JsonResponse
    {
        $task = Task::with(['assignments'])->findOrFail($id);

        /** @var \App\Models\User $user */
        $user = $request->user();

        // Проверка доступа к dealership задачи via Policy
        $this->authorize('updateStatus', $task);

        try {
            $task = $this->taskStatusService->transition($task, $user, $request->validated(), $request);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => config('app.debug')
                    ? 'Ошибка при обновлении статуса задачи: '.$e->getMessage()
                    : 'Ошибка при обновлении статуса задачи',
            ], 500);
        }

        return response()->json(TaskResource::make($task)->resolve());
    }

    /**
     * Получает историю выполненных задач текущего пользователя.
     *
     * @param  Request  $request  HTTP-запрос с параметрами фильтрации
     */
    public function myHistory(Request $request): JsonResponse
    {
        /** @var \App\Models\User $currentUser */
        $currentUser = $request->user();

        $query = Task::with([
            'creator',
            'dealership',
            'assignments.user',
            'responses' => function ($q) use ($currentUser) {
                $q->where('user_id', $currentUser->id)->with(['proofs', 'verifier']);
            },
        ])
            ->whereHas('assignments', function ($q) use ($currentUser) {
                $q->where('user_id', $currentUser->id);
            })
            ->whereHas('responses', function ($q) use ($currentUser) {
                $q->where('user_id', $currentUser->id);
            });

        // Фильтр по статусу ответа
        if ($request->filled('response_status')) {
            $status = $request->input('response_status');
            $query->whereHas('responses', function ($q) use ($currentUser, $status) {
                $q->where('user_id', $currentUser->id)->where('status', $status);
            });
        }

        // Фильтр по dealership
        if ($request->filled('dealership_id')) {
            $query->where('dealership_id', $request->input('dealership_id'));
        }

        // Сортировка
        $query->orderByDesc('updated_at');

        // Пагинация
        $perPage = min((int) $request->input('per_page', 15), 100);
        $tasks = $query->paginate($perPage);

        $tasksData = $tasks->getCollection()->map(fn ($task) => TaskResource::make($task)->resolve());

        return response()->json([
            'data' => $tasksData,
            'current_page' => $tasks->currentPage(),
            'last_page' => $tasks->lastPage(),
            'per_page' => $tasks->perPage(),
            'total' => $tasks->total(),
            'links' => [
                'first' => $tasks->url(1),
                'last' => $tasks->url($tasks->lastPage()),
                'prev' => $tasks->previousPageUrl(),
                'next' => $tasks->nextPageUrl(),
            ],
        ]);
    }
}
