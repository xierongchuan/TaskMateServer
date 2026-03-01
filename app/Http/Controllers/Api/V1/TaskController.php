<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Enums\ShiftStatus;
use App\Events\TaskAssigned;
use App\Events\TaskPendingReview;
use App\Helpers\TimeHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreTaskRequest;
use App\Http\Requests\Api\V1\UpdateTaskRequest;
use App\Http\Requests\Api\V1\UpdateTaskStatusRequest;
use App\Http\Resources\TaskResource;
use App\Jobs\StoreTaskSharedProofsJob;
use App\Models\Shift;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskDelegation;
use App\Services\SettingsService;
use App\Services\TaskFilterService;
use App\Services\TaskProofService;
use App\Services\TaskService;
use App\Services\TaskVerificationService;
use App\Traits\HasDealershipAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class TaskController extends Controller
{
    use HasDealershipAccess;

    public function __construct(
        private readonly TaskService $taskService,
        private readonly TaskFilterService $taskFilterService,
        private readonly TaskProofService $taskProofService,
        private readonly TaskVerificationService $taskVerificationService,
        private readonly SettingsService $settingsService
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
     * @param  Request  $request  HTTP-запрос со статусом и файлами
     * @param  int|string  $id  ID задачи
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateStatus(UpdateTaskStatusRequest $request, $id)
    {
        $task = Task::with(['assignments'])->findOrFail($id);

        /** @var \App\Models\User $user */
        $user = auth()->user();

        // Проверка доступа к dealership задачи via Policy
        $this->authorize('updateStatus', $task);

        $validated = $request->validated();

        $status = $validated['status'];
        $completeForAll = $validated['complete_for_all'] ?? false;

        // State Machine: проверка допустимых переходов статусов
        $existingResponse = $task->responses()->where('user_id', $user->id)->first();
        $currentStatus = $existingResponse?->status;

        if ($currentStatus !== null && ! $this->isValidStatusTransition($currentStatus, $status, $user)) {
            return response()->json([
                'message' => "Недопустимый переход статуса: {$currentStatus} -> {$status}",
            ], 422);
        }

        // Для задач с доказательством: проверяем наличие файлов
        if ($task->response_type === 'completion_with_proof') {
            // При попытке завершить задачу (не pending) требуется минимум 1 файл
            if (in_array($status, ['pending_review', 'completed']) && ! $request->hasFile('proof_files')) {
                // Для менеджеров/owners: проверяем ВСЕ proofs задачи (не только свои)
                if (in_array($user->role, [Role::MANAGER, Role::OWNER])) {
                    $hasAnyProofs = $task->responses()->whereHas('proofs')->exists()
                        || $task->sharedProofs()->exists();

                    if (! $hasAnyProofs) {
                        return response()->json([
                            'message' => 'Для выполнения этой задачи необходимо загрузить доказательство',
                        ], 422);
                    }
                } else {
                    // Для обычных пользователей: проверяем только свои proofs
                    $hasExistingProofs = $existingResponse && $existingResponse->proofs()->exists();

                    if (! $hasExistingProofs) {
                        return response()->json([
                            'message' => 'Для выполнения этой задачи необходимо загрузить доказательство',
                        ], 422);
                    }
                }
            }

            // Для задач с доказательством автоматически ставим pending_review при загрузке файлов
            if ($request->hasFile('proof_files') && $status === 'completed') {
                $status = 'pending_review';
            }
        }

        // Hybrid mode: check if open shift is required
        $shiftId = null;
        $completedDuringShift = false;

        if (in_array($status, ['pending_review', 'completed'])) {
            $requiresShift = (bool) $this->settingsService->getSettingWithFallback(
                'task_requires_open_shift',
                $task->dealership_id,
                false
            );

            // Find user's open shift
            $openShift = Shift::where('user_id', $user->id)
                ->whereNull('shift_end')
                ->where('status', ShiftStatus::OPEN->value)
                ->first();

            if ($requiresShift && ! $openShift) {
                // Managers and owners can complete without open shift
                if (! in_array($user->role, [Role::MANAGER, Role::OWNER])) {
                    return response()->json([
                        'message' => 'Для выполнения задачи необходимо открыть смену',
                    ], 422);
                }
            }

            if ($openShift) {
                $shiftId = $openShift->id;
                $completedDuringShift = true;
            }
        }

        // Используем транзакцию с блокировкой для предотвращения race conditions
        // при одновременном обновлении статусов (особенно в групповых задачах)
        $taskResponse = null;
        $filesData = null;
        $isResubmission = false;

        try {
            DB::transaction(function () use ($task, $user, $status, $request, $shiftId, $completedDuringShift, $completeForAll, $existingResponse, &$taskResponse, &$filesData, &$isResubmission) {
                // Блокируем задачу для предотвращения параллельных обновлений
                $task->lockForUpdate()->first();

                switch ($status) {
                    case 'pending':
                        $preserveProofs = $request->boolean('preserve_proofs', false);

                        if ($preserveProofs) {
                            // Мягкий сброс: только обновляем статус responses, файлы сохраняются
                            $task->responses()->update([
                                'status' => 'pending',
                                'verified_at' => null,
                                'verified_by' => null,
                                'rejection_reason' => null,
                            ]);
                        } else {
                            // Полный сброс: удаляем responses и все файлы
                            foreach ($task->responses as $response) {
                                $this->taskProofService->deleteAllProofs($response);
                            }
                            $task->responses()->delete();

                            // Удаляем shared proofs тоже
                            if ($task->sharedProofs()->exists()) {
                                $this->taskProofService->deleteSharedProofs($task);
                            }
                        }
                        break;

                    case 'acknowledged':
                        // Подтверждение для notification типа задач
                        $task->responses()->updateOrCreate(
                            ['user_id' => $user->id],
                            [
                                'status' => 'acknowledged',
                                'responded_at' => TimeHelper::nowUtc(),
                                'shift_id' => $shiftId,
                                'completed_during_shift' => $completedDuringShift,
                            ]
                        );
                        break;

                    case 'pending_review':
                    case 'completed':
                        // If manager/owner wants to complete for all assignees
                        if ($completeForAll && in_array($user->role, [Role::MANAGER, Role::OWNER])) {
                            // Create responses for ALL assigned users
                            $assignedUserIds = $task->assignments->pluck('user_id')->unique()->toArray();

                            foreach ($assignedUserIds as $assignedUserId) {
                                $task->responses()->updateOrCreate(
                                    ['user_id' => $assignedUserId],
                                    [
                                        'status' => $status,
                                        'responded_at' => TimeHelper::nowUtc(),
                                        'shift_id' => null, // Manager completes on behalf
                                        'completed_during_shift' => false,
                                        'submission_source' => 'shared',
                                        'uses_shared_proofs' => true,
                                    ]
                                );
                            }

                            // Подготовка файлов для асинхронной загрузки (выполняется после транзакции)
                            if ($request->hasFile('proof_files')) {
                                $files = $request->file('proof_files');
                                $filesData = [];

                                foreach ($files as $file) {
                                    // Сохраняем во временное хранилище
                                    $tempPath = $file->store('temp/task_proofs');

                                    $filesData[] = [
                                        'path' => $tempPath,
                                        'original_name' => $file->getClientOriginalName(),
                                        'mime' => $file->getMimeType(),
                                        'size' => $file->getSize(),
                                    ];
                                }
                            }
                        } else {
                            // Проверяем, это повторная отправка после отклонения
                            // (используем $existingResponse, полученный до транзакции)
                            $isResubmission = $existingResponse && $existingResponse->status === 'rejected';

                            // Update or create response for current user only
                            // При любом update очищаем поля верификации
                            $taskResponse = $task->responses()->updateOrCreate(
                                ['user_id' => $user->id],
                                [
                                    'status' => $status,
                                    'responded_at' => TimeHelper::nowUtc(),
                                    'shift_id' => $shiftId,
                                    'completed_during_shift' => $completedDuringShift,
                                    'verified_at' => null,
                                    'verified_by' => null,
                                    'rejection_reason' => null,
                                    'submission_source' => $isResubmission ? 'resubmitted' : 'individual',
                                    'uses_shared_proofs' => false,
                                ]
                            );
                        }
                        break;
                }
            });
        } catch (\Throwable $e) {
            Log::error('Task status update failed', [
                'task_id' => $id,
                'user_id' => $user->id,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => config('app.debug')
                    ? 'Ошибка при обновлении статуса задачи: '.$e->getMessage()
                    : 'Ошибка при обновлении статуса задачи',
            ], 500);
        }

        // Auto-cancel pending delegations when user acts on task
        if (in_array($status, ['pending_review', 'completed', 'acknowledged'])) {
            TaskDelegation::where('task_id', $task->id)
                ->where('from_user_id', $user->id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'cancelled',
                    'cancelled_by' => $user->id,
                    'responded_at' => TimeHelper::nowUtc(),
                ]);
        }

        // Публикуем событие в RabbitMQ для Telegram Bot
        if ($status === 'pending') {
            $assignedUserIds = $task->assignments->pluck('user_id')->toArray();
            event(new TaskAssigned($task, $assignedUserIds));
        }

        // Уведомляем менеджеров о новой задаче на проверку
        if ($status === 'pending_review' && $taskResponse !== null) {
            event(new TaskPendingReview($taskResponse));
        }

        // Асинхронные операции выполняются ПОСЛЕ успешного коммита транзакции
        // Это гарантирует, что Jobs будут работать с уже зафиксированными данными

        // Загрузка shared proofs (для completeForAll)
        if ($filesData !== null) {
            $tempDir = Storage::path('temp/task_proofs');
            if (is_dir($tempDir)) {
                chmod($tempDir, 0775);
            }

            StoreTaskSharedProofsJob::dispatch(
                $task->id,
                $filesData,
                $task->dealership_id
            );
        }

        // Загрузка individual proofs
        if ($taskResponse !== null && $request->hasFile('proof_files')) {
            try {
                $this->taskProofService->storeProofsAsync(
                    $taskResponse,
                    $request->file('proof_files'),
                    $task->dealership_id
                );

                // Записываем в историю верификации
                if ($isResubmission) {
                    $this->taskVerificationService->recordResubmission($taskResponse, $user);
                } else {
                    $this->taskVerificationService->recordSubmission($taskResponse, $user);
                }
            } catch (InvalidArgumentException $e) {
                return response()->json([
                    'message' => $e->getMessage(),
                ], 422);
            }
        }

        return response()->json(
            TaskResource::make(
                $task->refresh()
                    ->load(['assignments.user', 'responses.user', 'responses.proofs', 'responses.verifier', 'sharedProofs'])
            )->resolve()
        );
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

    /**
     * Проверяет допустимость перехода между статусами TaskResponse.
     *
     * Матрица допустимых переходов:
     * - pending -> acknowledged, pending_review, completed
     * - acknowledged -> pending_review, completed, pending
     * - pending_review -> completed, rejected, pending (через менеджера)
     * - rejected -> pending_review (переотправка)
     * - completed -> (финальный статус, менеджер может сбросить в pending)
     *
     * @param  string  $currentStatus  Текущий статус
     * @param  string  $newStatus  Новый статус
     * @param  \App\Models\User  $user  Пользователь, инициирующий переход
     */
    private function isValidStatusTransition(string $currentStatus, string $newStatus, $user): bool
    {
        // Менеджеры и владельцы могут сбрасывать любой статус в pending
        if ($newStatus === 'pending' && in_array($user->role, [Role::MANAGER, Role::OWNER])) {
            return true;
        }

        // Матрица допустимых переходов для обычных пользователей
        $allowedTransitions = [
            'pending' => ['acknowledged', 'pending_review', 'completed'],
            'acknowledged' => ['pending_review', 'completed'],
            'pending_review' => [], // Только через верификацию (approve/reject)
            'rejected' => ['pending_review', 'completed'], // Переотправка (completed для задач без доказательств)
            'completed' => [], // Финальный статус
        ];

        // Менеджеры могут делать дополнительные переходы
        if (in_array($user->role, [Role::MANAGER, Role::OWNER])) {
            $allowedTransitions['acknowledged'][] = 'pending';
            $allowedTransitions['pending_review'][] = 'pending';
            $allowedTransitions['pending_review'][] = 'completed'; // Approve без верификации
        }

        return in_array($newStatus, $allowedTransitions[$currentStatus] ?? []);
    }
}
