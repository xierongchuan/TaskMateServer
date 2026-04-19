<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Role;
use App\Enums\ShiftStatus;
use App\Events\TaskAssigned;
use App\Events\TaskPendingReview;
use App\Helpers\TimeHelper;
use App\Jobs\StoreTaskSharedProofsJob;
use App\Models\Shift;
use App\Models\Task;
use App\Models\TaskDelegation;
use App\Models\TaskResponse;
use App\Models\User;
use App\StateMachines\TaskStatusMachine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Сервис для управления переходами статусов задач.
 *
 * Централизует всю логику state machine, проверку доказательств,
 * привязку смен и post-transition действия (события, jobs, делегации).
 *
 * Single Responsibility: только управление жизненным циклом статусов TaskResponse.
 */
class TaskStatusService
{
    public function __construct(
        private readonly TaskProofService $taskProofService,
        private readonly TaskVerificationService $taskVerificationService,
        private readonly SettingsService $settingsService,
        private readonly TaskStatusMachine $statusMachine = new TaskStatusMachine,
    ) {}

    /**
     * Выполнить переход статуса задачи.
     *
     * Оркестрирует все этапы: валидация, проверка доказательств,
     * контекст смены, транзакционное обновление, post-transition действия.
     *
     * @param  Task  $task  Задача (должна иметь загруженный relationships: assignments)
     * @param  User  $user  Пользователь, инициирующий переход
     * @param  array<string, mixed>  $data  Валидированные данные из FormRequest
     * @param  Request  $request  HTTP-запрос (для доступа к файлам)
     * @return Task Обновлённая задача с загруженными relationships
     *
     * @throws InvalidArgumentException При ошибке валидации файлов
     * @throws \RuntimeException При ошибке обновления в БД
     */
    public function transition(Task $task, User $user, array $data, Request $request): Task
    {
        $status = $data['status'];
        $completeForAll = $data['complete_for_all'] ?? false;

        // Получаем текущий response пользователя ДО транзакции для корректной проверки
        $existingResponse = $task->responses()->where('user_id', $user->id)->first();

        $this->validateTransition($task, $user, $existingResponse, $status);
        $this->validateProofs($task, $user, $existingResponse, $status, $request);

        // Автоматический перевод completed -> pending_review при загрузке файлов
        if ($task->response_type === 'completion_with_proof'
            && $request->hasFile('proof_files')
            && $status === 'completed') {
            $status = 'pending_review';
        }

        $shiftContext = $this->resolveShiftContext($task, $user, $status);

        $transitionResult = null;

        try {
            $transitionResult = DB::transaction(
                fn () => $this->executeTransition(
                    $task,
                    $user,
                    $status,
                    $completeForAll,
                    $existingResponse,
                    $shiftContext,
                    $request,
                )
            );
        } catch (\Throwable $e) {
            Log::error('Task status update failed', [
                'task_id' => $task->id,
                'user_id' => $user->id,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $this->postTransitionActions($task, $user, $status, $transitionResult, $request);

        return $task->refresh()->load([
            'assignments.user',
            'responses.user',
            'responses.proofs',
            'responses.verifier',
            'sharedProofs',
        ]);
    }

    /**
     * Проверяет допустимость перехода между статусами TaskResponse.
     *
     * Делегирует валидацию TaskStatusMachine, которая содержит
     * декларативную матрицу допустимых переходов.
     *
     * @param  Task  $task  Задача
     * @param  User  $user  Пользователь, инициирующий переход
     * @param  TaskResponse|null  $existingResponse  Текущий response пользователя
     * @param  string  $newStatus  Новый статус
     *
     * @throws \App\Exceptions\InvalidStatusTransitionException При недопустимом переходе
     */
    public function validateTransition(
        Task $task,
        User $user,
        ?TaskResponse $existingResponse,
        string $newStatus,
    ): void {
        $currentStatus = $existingResponse?->status;

        $this->statusMachine->validateTransition($currentStatus, $newStatus, $user);
    }

    /**
     * Проверяет наличие файлов доказательств для задач типа completion_with_proof.
     *
     * Для менеджеров/owners проверяет все proofs задачи.
     * Для обычных пользователей — только их собственные proofs.
     *
     * @param  Task  $task  Задача
     * @param  User  $user  Пользователь
     * @param  TaskResponse|null  $existingResponse  Текущий response пользователя
     * @param  string  $status  Целевой статус
     * @param  Request  $request  HTTP-запрос с файлами
     *
     * @throws \RuntimeException При отсутствии обязательных доказательств
     */
    public function validateProofs(
        Task $task,
        User $user,
        ?TaskResponse $existingResponse,
        string $status,
        Request $request,
    ): void {
        if ($task->response_type !== 'completion_with_proof') {
            return;
        }

        if (! in_array($status, ['pending_review', 'completed'])) {
            return;
        }

        if ($request->hasFile('proof_files')) {
            return;
        }

        // Для менеджеров/owners: проверяем ВСЕ proofs задачи (не только свои)
        if (in_array($user->role, [Role::MANAGER, Role::OWNER])) {
            $hasAnyProofs = $task->responses()->whereHas('proofs')->exists()
                || $task->sharedProofs()->exists();

            if (! $hasAnyProofs) {
                throw new \RuntimeException(
                    'Для выполнения этой задачи необходимо загрузить доказательство'
                );
            }

            return;
        }

        // Для обычных пользователей: проверяем только свои proofs
        $hasExistingProofs = $existingResponse && $existingResponse->proofs()->exists();

        if (! $hasExistingProofs) {
            throw new \RuntimeException(
                'Для выполнения этой задачи необходимо загрузить доказательство'
            );
        }
    }

    /**
     * Определяет контекст смены для выполнения задачи (hybrid mode).
     *
     * Если настройка task_requires_open_shift включена и у пользователя нет
     * открытой смены, сотрудники (не менеджеры/owners) получат ошибку.
     *
     * @param  Task  $task  Задача
     * @param  User  $user  Пользователь
     * @param  string  $status  Целевой статус
     * @return array{shift_id: int|null, completed_during_shift: bool}
     *
     * @throws \RuntimeException Если смена требуется, но не открыта
     */
    public function resolveShiftContext(Task $task, User $user, string $status): array
    {
        if (! in_array($status, ['pending_review', 'completed'])) {
            return ['shift_id' => null, 'completed_during_shift' => false];
        }

        $requiresShift = (bool) $this->settingsService->getSettingWithFallback(
            'task_requires_open_shift',
            $task->dealership_id,
            false
        );

        $openShift = Shift::where('user_id', $user->id)
            ->whereNull('shift_end')
            ->where('status', ShiftStatus::OPEN->value)
            ->first();

        if ($requiresShift && ! $openShift) {
            if (! in_array($user->role, [Role::MANAGER, Role::OWNER])) {
                throw new \RuntimeException(
                    'Для выполнения задачи необходимо открыть смену'
                );
            }
        }

        return [
            'shift_id' => $openShift?->id,
            'completed_during_shift' => $openShift !== null,
        ];
    }

    /**
     * Выполняет транзакционное обновление статуса.
     *
     * Диспетчеризует по ветке перехода:
     * - pending — сброс (полный или мягкий)
     * - acknowledged — подтверждение уведомления
     * - pending_review / completed — выполнение (индивидуальное или для всех)
     *
     * @param  Task  $task  Задача
     * @param  User  $user  Пользователь
     * @param  string  $status  Целевой статус
     * @param  bool  $completeForAll  Выполнить для всех назначенных пользователей
     * @param  TaskResponse|null  $existingResponse  Текущий response (до транзакции)
     * @param  array{shift_id: int|null, completed_during_shift: bool}  $shiftContext  Контекст смены
     * @param  Request  $request  HTTP-запрос с файлами
     * @return array{task_response: TaskResponse|null, files_data: array|null, is_resubmission: bool}
     */
    private function executeTransition(
        Task $task,
        User $user,
        string $status,
        bool $completeForAll,
        ?TaskResponse $existingResponse,
        array $shiftContext,
        Request $request,
    ): array {
        // Блокируем задачу для предотвращения параллельных обновлений
        $task->lockForUpdate()->first();

        return match ($status) {
            'pending' => $this->handlePending($task, $request),
            'acknowledged' => $this->handleAcknowledged($task, $user, $shiftContext),
            'pending_review', 'completed' => $completeForAll && in_array($user->role, [Role::MANAGER, Role::OWNER])
                ? $this->handleCompleteForAll($task, $user, $status, $request)
                : $this->handleIndividual($task, $user, $status, $existingResponse, $shiftContext),
            default => throw new \RuntimeException("Неизвестный статус: {$status}"),
        };
    }

    /**
     * Обрабатывает переход в статус pending (сброс задачи).
     *
     * Полный сброс: удаляет все responses и файлы.
     * Мягкий сброс (preserve_proofs=true): только обновляет статусы responses.
     *
     * @return array{task_response: null, files_data: null, is_resubmission: false}
     */
    private function handlePending(Task $task, Request $request): array
    {
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

        return ['task_response' => null, 'files_data' => null, 'is_resubmission' => false, 'is_update' => false];
    }

    /**
     * Обрабатывает переход в статус acknowledged (подтверждение уведомления).
     *
     * @param  array{shift_id: int|null, completed_during_shift: bool}  $shiftContext
     * @return array{task_response: null, files_data: null, is_resubmission: false}
     */
    private function handleAcknowledged(Task $task, User $user, array $shiftContext): array
    {
        $task->responses()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'status' => 'acknowledged',
                'responded_at' => TimeHelper::nowUtc(),
                'shift_id' => $shiftContext['shift_id'],
                'completed_during_shift' => $shiftContext['completed_during_shift'],
            ]
        );

        return ['task_response' => null, 'files_data' => null, 'is_resubmission' => false, 'is_update' => false];
    }

    /**
     * Обрабатывает выполнение задачи от имени всех назначенных пользователей.
     *
     * Создаёт responses для ВСЕХ assignees с submission_source=shared.
     * Файлы сохраняются временно и передаются в StoreTaskSharedProofsJob.
     *
     * @param  string  $status  pending_review или completed
     * @return array{task_response: null, files_data: array|null, is_resubmission: false}
     */
    private function handleCompleteForAll(Task $task, User $user, string $status, Request $request): array
    {
        $assignedUserIds = $task->assignments->pluck('user_id')->unique()->toArray();

        foreach ($assignedUserIds as $assignedUserId) {
            $task->responses()->updateOrCreate(
                ['user_id' => $assignedUserId],
                [
                    'status' => $status,
                    'responded_at' => TimeHelper::nowUtc(),
                    'shift_id' => null, // Менеджер выполняет от лица сотрудников
                    'completed_during_shift' => false,
                    'submission_source' => 'shared',
                    'uses_shared_proofs' => true,
                ]
            );
        }

        // Подготовка файлов для асинхронной загрузки (выполняется после транзакции)
        $filesData = null;
        if ($request->hasFile('proof_files')) {
            $filesData = [];

            foreach ($request->file('proof_files') as $file) {
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

        return ['task_response' => null, 'files_data' => $filesData, 'is_resubmission' => false, 'is_update' => false];
    }

    /**
     * Обрабатывает индивидуальное выполнение задачи пользователем.
     *
     * Определяет, является ли это повторной отправкой (rejected -> pending_review).
     * Создаёт или обновляет response с очисткой полей верификации.
     *
     * @param  string  $status  pending_review или completed
     * @param  array{shift_id: int|null, completed_during_shift: bool}  $shiftContext
     * @return array{task_response: TaskResponse, files_data: null, is_resubmission: bool}
     */
    private function handleIndividual(
        Task $task,
        User $user,
        string $status,
        ?TaskResponse $existingResponse,
        array $shiftContext,
    ): array {
        // Проверяем, это повторная отправка после отклонения
        // (используем $existingResponse, полученный до транзакции)
        $isResubmission = $existingResponse && $existingResponse->status === 'rejected';

        // Для менеджеров/владельцев: обновление доказательств в pending_review
        $isPrivilegedUpdate = $existingResponse && $existingResponse->status === 'pending_review'
            && in_array($user->role, [Role::MANAGER, Role::OWNER]);

        $taskResponse = $task->responses()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'status' => $status,
                'responded_at' => TimeHelper::nowUtc(),
                'shift_id' => $shiftContext['shift_id'],
                'completed_during_shift' => $shiftContext['completed_during_shift'],
                'verified_at' => null,
                'verified_by' => null,
                'rejection_reason' => null,
                'submission_source' => $isResubmission ? 'resubmitted' : ($isPrivilegedUpdate ? 'updated' : 'individual'),
                'uses_shared_proofs' => false,
            ]
        );

        return ['task_response' => $taskResponse, 'files_data' => null, 'is_resubmission' => $isResubmission, 'is_update' => $isPrivilegedUpdate];
    }

    /**
     * Выполняет действия после успешного коммита транзакции.
     *
     * Порядок: авто-отмена делегаций → dispatch событий → загрузка файлов.
     * Асинхронные операции запускаются ПОСЛЕ коммита, чтобы Jobs работали
     * с уже зафиксированными данными.
     *
     * @param  Task  $task  Задача
     * @param  User  $user  Пользователь
     * @param  string  $status  Применённый статус
     * @param  array{task_response: TaskResponse|null, files_data: array|null, is_resubmission: bool}  $transitionResult  Результат транзакции
     * @param  Request  $request  HTTP-запрос с файлами
     *
     * @throws InvalidArgumentException При ошибке валидации файлов
     */
    private function postTransitionActions(
        Task $task,
        User $user,
        string $status,
        array $transitionResult,
        Request $request,
    ): void {
        $taskResponse = $transitionResult['task_response'];
        $filesData = $transitionResult['files_data'];
        $isResubmission = $transitionResult['is_resubmission'];
        $isUpdate = $transitionResult['is_update'] ?? false;

        // Авто-отмена pending делегаций при активном действии пользователя
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

        // Публикуем событие в RabbitMQ для Telegram Bot при сбросе задачи
        if ($status === 'pending') {
            $assignedUserIds = $task->assignments->pluck('user_id')->toArray();
            event(new TaskAssigned($task, $assignedUserIds));
        }

        // Уведомляем менеджеров о новой задаче на проверку
        if ($status === 'pending_review' && $taskResponse !== null) {
            event(new TaskPendingReview($taskResponse));
        }

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
            // Для повторной отправки или обновления — удаляем старые доказательства
            if ($isResubmission || $isUpdate) {
                $this->taskProofService->deleteAllProofs($taskResponse);
            }

            $this->taskProofService->storeProofsAsync(
                $taskResponse,
                $request->file('proof_files'),
                $task->dealership_id
            );

            // Записываем в историю верификации
            if ($isResubmission) {
                $this->taskVerificationService->recordResubmission($taskResponse, $user);
            } elseif ($isUpdate) {
                // Для обновления можно записать как submission
                $this->taskVerificationService->recordSubmission($taskResponse, $user);
            } else {
                $this->taskVerificationService->recordSubmission($taskResponse, $user);
            }
        }
    }
}
