<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\TaskProof;
use App\Models\TaskResponse;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Job для асинхронной загрузки индивидуальных доказательств.
 */
class StoreTaskProofsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Количество попыток выполнения.
     */
    public int $tries = 3;

    /**
     * Задержка между попытками (секунды).
     */
    public int $backoff = 60;

    private const STORAGE_DISK = 'task_proofs';

    /**
     * @param int $taskResponseId ID ответа на задачу
     * @param array<array{path: string, original_name: string, mime: string, size: int, user_id: int}> $filesData
     * @param int $dealershipId ID автосалона
     * @param int $taskId ID задачи
     */
    public function __construct(
        public readonly int $taskResponseId,
        public readonly array $filesData,
        public readonly int $dealershipId,
        public readonly int $taskId
    ) {
        $this->onQueue('proof_upload');
    }

    public function handle(): void
    {
        $taskResponse = TaskResponse::find($this->taskResponseId);

        if (!$taskResponse) {
            Log::warning('StoreTaskProofsJob: TaskResponse not found', ['id' => $this->taskResponseId]);
            $this->cleanupTempFiles();

            return;
        }

        DB::beginTransaction();
        try {
            foreach ($this->filesData as $fileData) {
                $this->storeFile($taskResponse, $fileData);
            }
            DB::commit();
            Log::info('StoreTaskProofsJob completed', [
                'task_response_id' => $this->taskResponseId,
                'files' => count($this->filesData),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('StoreTaskProofsJob failed', [
                'task_response_id' => $this->taskResponseId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Сохранить файл в постоянное хранилище и создать запись в БД.
     *
     * @param TaskResponse $taskResponse Ответ на задачу
     * @param array{path: string, original_name: string, mime: string, size: int, user_id: int} $fileData Данные файла
     */
    private function storeFile(TaskResponse $taskResponse, array $fileData): void
    {
        $extension = pathinfo($fileData['original_name'], PATHINFO_EXTENSION);
        $filename = sprintf(
            'proof_%d_%d_%s.%s',
            time(),
            $fileData['user_id'],
            bin2hex(random_bytes(8)),
            $extension
        );

        $date = date('Y/m/d');
        $destinationPath = sprintf(
            'dealerships/%d/tasks/%d/%s/%s',
            $this->dealershipId,
            $this->taskId,
            $date,
            $filename
        );

        // Перемещаем из temp в постоянное хранилище
        $content = Storage::get($fileData['path']);
        if ($content === null) {
            throw new \RuntimeException("Temp file not found: {$fileData['path']}");
        }

        if (!Storage::disk(self::STORAGE_DISK)->put($destinationPath, $content)) {
            throw new \RuntimeException("Failed to store file: {$fileData['original_name']}");
        }

        // Удаляем temp файл
        Storage::delete($fileData['path']);

        // Создаём запись в БД
        TaskProof::create([
            'task_response_id' => $taskResponse->id,
            'file_path' => $destinationPath,
            'original_filename' => $fileData['original_name'],
            'mime_type' => $fileData['mime'],
            'file_size' => $fileData['size'],
        ]);
    }

    /**
     * Очистить temp-файлы при неуспешной обработке.
     */
    private function cleanupTempFiles(): void
    {
        foreach ($this->filesData as $fileData) {
            if (Storage::exists($fileData['path'])) {
                Storage::delete($fileData['path']);
            }
        }
    }

    /**
     * Обработка окончательного провала job.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('StoreTaskProofsJob failed permanently', [
            'task_response_id' => $this->taskResponseId,
            'error' => $exception->getMessage(),
        ]);
        $this->cleanupTempFiles();
    }
}
