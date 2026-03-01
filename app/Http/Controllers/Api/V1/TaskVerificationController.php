<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RejectTaskResponseRequest;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use App\Models\TaskResponse;
use App\Services\TaskVerificationService;
use App\Traits\ApiResponses;
use App\Traits\HasDealershipAccess;
use Illuminate\Http\JsonResponse;

/**
 * Контроллер для верификации доказательств выполнения задач.
 *
 * Доступен только менеджерам и владельцам.
 */
class TaskVerificationController extends Controller
{
    use ApiResponses, HasDealershipAccess;

    public function __construct(
        private readonly TaskVerificationService $verificationService
    ) {}

    /**
     * Одобрить доказательство выполнения.
     *
     * Статус ответа меняется на 'completed'.
     *
     * @param  int|string  $id  ID ответа на задачу (task_response)
     */
    public function approve($id): JsonResponse
    {
        $taskResponse = TaskResponse::with(['task.sharedProofs', 'proofs'])->findOrFail($id);

        /** @var \App\Models\User $currentUser */
        $currentUser = auth()->user();
        $task = $taskResponse->task;

        // Проверка доступа к автосалону via Policy
        $this->authorize('verify', $taskResponse);

        // Проверка статуса
        if ($taskResponse->status !== 'pending_review') {
            return $this->errorResponse('Этот ответ не требует верификации', 422);
        }

        // Проверка наличия доказательств (только для задач с типом completion_with_proof)
        // Используем effectiveProofs — учитывает как индивидуальные proofs, так и shared_proofs задачи
        if ($task->response_type === 'completion_with_proof' && $taskResponse->effectiveProofs->isEmpty()) {
            return $this->errorResponse('Нет доказательств для верификации', 422);
        }

        // Одобряем через сервис (записывает историю)
        $this->verificationService->approve($taskResponse, $currentUser);

        return $this->successResponse(
            TaskResource::make(
                $task->refresh()
                    ->load(['assignments.user', 'responses.user', 'responses.proofs', 'responses.verifier'])
            )->resolve(),
            'Доказательство одобрено'
        );
    }

    /**
     * Отклонить доказательство выполнения.
     *
     * Статус ответа меняется на 'pending', файлы удаляются.
     *
     * @param  int|string  $id  ID ответа на задачу (task_response)
     */
    public function reject(RejectTaskResponseRequest $request, $id): JsonResponse
    {
        $validated = $request->validated();

        $taskResponse = TaskResponse::with(['task', 'proofs'])->findOrFail($id);

        /** @var \App\Models\User $currentUser */
        $currentUser = auth()->user();
        $task = $taskResponse->task;

        // Проверка доступа к автосалону via Policy
        $this->authorize('verify', $taskResponse);

        // Проверка статуса
        if ($taskResponse->status !== 'pending_review') {
            return $this->errorResponse('Этот ответ не требует верификации', 422);
        }

        // Отклоняем через сервис (удаляет файлы, записывает историю, статус -> 'rejected')
        $this->verificationService->reject($taskResponse, $currentUser, $validated['reason']);

        return $this->successResponse(
            TaskResource::make(
                $task->refresh()
                    ->load(['assignments.user', 'responses.user', 'responses.proofs', 'responses.verifier'])
            )->resolve(),
            'Доказательство отклонено'
        );
    }

    /**
     * Отклонить все pending_review ответы для задачи.
     *
     * Используется для групповых задач — отклоняет все ожидающие
     * проверки ответы одним действием с одной причиной.
     *
     * @param  int|string  $taskId  ID задачи
     */
    public function rejectAll(RejectTaskResponseRequest $request, $taskId): JsonResponse
    {
        $validated = $request->validated();

        $task = Task::with(['responses.proofs', 'sharedProofs'])->findOrFail($taskId);

        /** @var \App\Models\User $currentUser */
        $currentUser = auth()->user();

        // Проверка доступа к автосалону via Policy (reuse updateStatus ability on task)
        $this->authorize('updateStatus', $task);

        // Проверяем наличие pending_review responses
        $pendingCount = $task->responses->where('status', 'pending_review')->count();
        if ($pendingCount === 0) {
            return $this->errorResponse('Нет ответов, ожидающих проверки', 422);
        }

        $this->verificationService->rejectAllForTask($task, $currentUser, $validated['reason']);

        return $this->successResponse(
            TaskResource::make(
                $task->refresh()
                    ->load(['assignments.user', 'responses.user', 'responses.proofs', 'responses.verifier', 'sharedProofs'])
            )->resolve(),
            'Все ответы отклонены'
        );
    }
}
