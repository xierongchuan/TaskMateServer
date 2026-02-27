<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TaskProof;
use App\Models\TaskSharedProof;
use App\Services\TaskProofService;
use App\Traits\HasDealershipAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Контроллер для работы с доказательствами выполнения задач.
 */
class TaskProofController extends Controller
{
    use HasDealershipAccess;

    public function __construct(
        private readonly TaskProofService $taskProofService
    ) {}

    /**
     * Получить информацию о доказательстве.
     *
     * @param int|string $id ID доказательства
     * @return JsonResponse
     */
    public function show($id): JsonResponse
    {
        $proof = TaskProof::with(['taskResponse.task'])->find($id);

        if (!$proof) {
            return response()->json([
                'message' => 'Доказательство не найдено'
            ], 404);
        }

        /** @var \App\Models\User $currentUser */
        $currentUser = auth()->user();
        $task = $proof->taskResponse->task;

        // Проверка доступа к задаче
        if (!$this->isOwner($currentUser)) {
            $isCreator = $task->creator_id === $currentUser->id;
            $isAssigned = $task->assignments()->where('user_id', $currentUser->id)->exists();
            $hasAccess = $this->hasAccessToDealership($currentUser, $task->dealership_id);

            if (!$hasAccess && !$isCreator && !$isAssigned) {
                return response()->json([
                    'message' => 'У вас нет доступа к этому доказательству'
                ], 403);
            }
        }

        return response()->json([
            'data' => $proof->toApiArray()
        ]);
    }

    /**
     * Скачать файл доказательства.
     *
     * Доступ по подписанному URL (без auth:sanctum middleware).
     * URL генерируется в модели TaskProof::getUrlAttribute().
     *
     * Безопасность обеспечивается подписанным URL:
     * - URL генерируется только для авторизованных пользователей
     * - URL имеет ограниченное время жизни (60 мин)
     * - Проверка прав происходит при генерации URL, а не при скачивании
     *
     * @param Request $request HTTP-запрос
     * @param int|string $id ID доказательства
     * @return StreamedResponse|JsonResponse
     */
    public function download(Request $request, $id): StreamedResponse|JsonResponse
    {
        // Проверка подписи URL (единственная проверка безопасности)
        if (!$request->hasValidSignature()) {
            return response()->json([
                'message' => 'Ссылка недействительна или истекла'
            ], 403);
        }

        $proof = TaskProof::with(['taskResponse.task'])->find($id);

        if (!$proof) {
            return response()->json([
                'message' => 'Доказательство не найдено'
            ], 404);
        }

        // Проверяем существование файла
        $filePath = $this->taskProofService->getFilePath($proof);

        if (!$filePath || !file_exists($filePath)) {
            return response()->json([
                'message' => 'Файл не найден на сервере'
            ], 404);
        }

        // Определяем Content-Type и Content-Disposition
        $mimeType = $proof->mime_type ?: 'application/octet-stream';
        $filename = $proof->original_filename;

        // Для изображений и PDF отдаём inline, для остальных — attachment
        $disposition = $this->getContentDisposition($mimeType);

        return response()->streamDownload(
            function () use ($filePath) {
                $stream = fopen($filePath, 'rb');
                if ($stream) {
                    fpassthru($stream);
                    fclose($stream);
                }
            },
            $filename,
            [
                'Content-Type' => $mimeType,
                'Content-Disposition' => $disposition . '; filename="' . $this->sanitizeFilename($filename) . '"',
                'Content-Length' => $proof->file_size,
                'Cache-Control' => 'private, max-age=3600',
            ]
        );
    }

    /**
     * Скачать общий файл задачи.
     *
     * @param Request $request
     * @param int|string $id ID общего доказательства
     * @return StreamedResponse|JsonResponse
     */
    public function downloadShared(Request $request, $id): StreamedResponse|JsonResponse
    {
        // Проверка подписи URL (единственная проверка безопасности)
        // Доступ контролируется при генерации подписанного URL, а не при скачивании
        if (!$request->hasValidSignature()) {
            return response()->json([
                'message' => 'Ссылка недействительна или истекла'
            ], 403);
        }

        $proof = TaskSharedProof::find($id);

        if (!$proof) {
            return response()->json([
                'message' => 'Доказательство не найдено'
            ], 404);
        }

        // Проверяем существование файла на диске task_proofs (новая структура)
        // или на диске local (старая структура для обратной совместимости)
        $filePath = null;

        if (Storage::disk('task_proofs')->exists($proof->file_path)) {
            $filePath = Storage::disk('task_proofs')->path($proof->file_path);
        } elseif (Storage::disk('local')->exists($proof->file_path)) {
            $filePath = Storage::disk('local')->path($proof->file_path);
        }

        if (!$filePath || !file_exists($filePath)) {
            return response()->json([
                'message' => 'Файл не найден на сервере'
            ], 404);
        }

        // Определяем Content-Type и Content-Disposition
        $mimeType = $proof->mime_type ?: 'application/octet-stream';
        $filename = $proof->original_filename;

        // Для изображений и PDF отдаём inline, для остальных — attachment
        $disposition = $this->getContentDisposition($mimeType);

        return response()->streamDownload(
            function () use ($filePath) {
                $stream = fopen($filePath, 'rb');
                if ($stream) {
                    fpassthru($stream);
                    fclose($stream);
                }
            },
            $filename,
            [
                'Content-Type' => $mimeType,
                'Content-Disposition' => $disposition . '; filename="' . $this->sanitizeFilename($filename) . '"',
                'Content-Length' => $proof->file_size,
                'Cache-Control' => 'private, max-age=3600',
            ]
        );
    }

    /**
     * Удалить доказательство.
     *
     * Доступно только менеджерам и владельцам.
     *
     * @param int|string $id ID доказательства
     * @return JsonResponse
     */
    public function destroy($id): JsonResponse
    {
        $proof = TaskProof::with(['taskResponse.task'])->find($id);

        if (!$proof) {
            return response()->json([
                'message' => 'Доказательство не найдено'
            ], 404);
        }

        /** @var \App\Models\User $currentUser */
        $currentUser = auth()->user();
        $task = $proof->taskResponse->task;

        // Запрет удаления файлов выполненных задач
        if (in_array($task->status, ['completed', 'completed_late'])) {
            return response()->json([
                'message' => 'Нельзя удалять файлы выполненной задачи'
            ], 422);
        }

        // Проверка доступа (только владелец proof или менеджер/владелец автосалона)
        $isProofOwner = $proof->taskResponse->user_id === $currentUser->id;
        $hasManageAccess = $this->hasAccessToDealership($currentUser, $task->dealership_id)
            && in_array($currentUser->role->value, ['manager', 'owner']);

        if (!$isProofOwner && !$hasManageAccess && !$this->isOwner($currentUser)) {
            return response()->json([
                'message' => 'У вас нет прав для удаления этого доказательства'
            ], 403);
        }

        $this->taskProofService->deleteProof($proof);

        return response()->json([
            'message' => 'Доказательство успешно удалено'
        ]);
    }

    /**
     * Удалить общий файл задачи (shared proof).
     *
     * Доступно только менеджерам и владельцам.
     *
     * @param int|string $id ID общего файла
     * @return JsonResponse
     */
    public function destroyShared($id): JsonResponse
    {
        $proof = TaskSharedProof::with(['task'])->find($id);

        if (!$proof) {
            return response()->json([
                'message' => 'Файл не найден'
            ], 404);
        }

        /** @var \App\Models\User $currentUser */
        $currentUser = auth()->user();
        $task = $proof->task;

        // Запрет удаления файлов выполненных задач
        if (in_array($task->status, ['completed', 'completed_late'])) {
            return response()->json([
                'message' => 'Нельзя удалять файлы выполненной задачи'
            ], 422);
        }

        // Проверка доступа (только менеджер/владелец автосалона)
        $hasManageAccess = $this->hasAccessToDealership($currentUser, $task->dealership_id)
            && in_array($currentUser->role->value, ['manager', 'owner']);

        if (!$hasManageAccess && !$this->isOwner($currentUser)) {
            return response()->json([
                'message' => 'У вас нет прав для удаления этого файла'
            ], 403);
        }

        $this->taskProofService->deleteSharedProof($proof);

        return response()->json([
            'message' => 'Файл успешно удалён'
        ]);
    }

    /**
     * Определить Content-Disposition для типа файла.
     *
     * Изображения и PDF открываются в браузере (inline),
     * остальные файлы скачиваются (attachment).
     */
    private function getContentDisposition(string $mimeType): string
    {
        // Типы, которые открываются в браузере (inline)
        // PDF и текстовые файлы убраны - они скачиваются
        $inlineTypes = [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'video/mp4',
            'video/webm',
            'video/quicktime',
            'audio/mpeg',
            'audio/wav',
            'audio/ogg',
            'audio/mp4',
        ];

        return in_array($mimeType, $inlineTypes, true) ? 'inline' : 'attachment';
    }

    /**
     * Очистить имя файла для безопасного использования в заголовках.
     */
    private function sanitizeFilename(string $filename): string
    {
        // Удаляем потенциально опасные символы
        $filename = preg_replace('/[^\p{L}\p{N}\s\.\-_]/u', '', $filename);

        // Ограничиваем длину
        if (mb_strlen($filename) > 200) {
            $extension = pathinfo($filename, PATHINFO_EXTENSION);
            $name = mb_substr(pathinfo($filename, PATHINFO_FILENAME), 0, 195 - mb_strlen($extension));
            $filename = $name . '.' . $extension;
        }

        return $filename ?: 'file';
    }
}
