<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Helpers\TaskProofPathGenerator;
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
     * @param  int  $taskResponseId  ID ответа на задачу
     * @param  array<array{path: string, original_name: string, mime: string, size: int, user_id: int}>  $filesData
     * @param  int  $dealershipId  ID автосалона
     * @param  int  $taskId  ID задачи
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
        Log::info('StoreTaskProofsJob: Started processing', [
            'task_response_id' => $this->taskResponseId,
            'files_count' => count($this->filesData),
            'dealership_id' => $this->dealershipId,
            'task_id' => $this->taskId,
        ]);

        $taskResponse = TaskResponse::find($this->taskResponseId);

        if (! $taskResponse) {
            Log::warning('StoreTaskProofsJob: TaskResponse not found', ['id' => $this->taskResponseId]);
            $this->cleanupTempFiles();

            return;
        }

        DB::beginTransaction();
        try {
            foreach ($this->filesData as $index => $fileData) {
                Log::info('StoreTaskProofsJob: Processing file', [
                    'task_response_id' => $this->taskResponseId,
                    'file_index' => $index,
                    'original_name' => $fileData['original_name'],
                    'temp_path' => $fileData['path'],
                ]);
                $this->storeFile($taskResponse, $fileData);
                Log::info('StoreTaskProofsJob: File processed successfully', [
                    'task_response_id' => $this->taskResponseId,
                    'file_index' => $index,
                ]);
            }
            DB::commit();
            Log::info('StoreTaskProofsJob completed successfully', [
                'task_response_id' => $this->taskResponseId,
                'files' => count($this->filesData),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('StoreTaskProofsJob failed with exception', [
                'task_response_id' => $this->taskResponseId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Сохранить файл в постоянное хранилище и создать запись в БД.
     *
     * @param  TaskResponse  $taskResponse  Ответ на задачу
     * @param  array{path: string, original_name: string, mime: string, size: int, user_id: int}  $fileData  Данные файла
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

        $destinationPath = TaskProofPathGenerator::generatePath(
            $this->dealershipId,
            $this->taskId,
            $filename
        );

        Log::info('StoreTaskProofsJob: Storing file', [
            'task_response_id' => $taskResponse->id,
            'filename' => $filename,
            'destination_path' => $destinationPath,
            'temp_path' => $fileData['path'],
        ]);

        // Перемещаем из temp в постоянное хранилище
        $content = Storage::get($fileData['path']);
        if ($content === null) {
            Log::error('StoreTaskProofsJob: Temp file not found', [
                'temp_path' => $fileData['path'],
                'exists' => Storage::exists($fileData['path']),
            ]);
            throw new \RuntimeException("Temp file not found: {$fileData['path']}");
        }

        Log::info('StoreTaskProofsJob: Temp file read successfully', [
            'temp_path' => $fileData['path'],
            'content_size' => strlen($content),
        ]);

        if (! Storage::disk(self::STORAGE_DISK)->put($destinationPath, $content)) {
            Log::error('StoreTaskProofsJob: Failed to store file to disk', [
                'destination_path' => $destinationPath,
                'disk' => self::STORAGE_DISK,
            ]);
            throw new \RuntimeException("Failed to store file: {$fileData['original_name']}");
        }

        Log::info('StoreTaskProofsJob: File stored to disk successfully', [
            'destination_path' => $destinationPath,
            'disk' => self::STORAGE_DISK,
        ]);

        // Удаляем temp файл
        Storage::delete($fileData['path']);

        // Создаём запись в БД
        $proof = TaskProof::create([
            'task_response_id' => $taskResponse->id,
            'file_path' => $destinationPath,
            'original_filename' => $fileData['original_name'],
            'mime_type' => $fileData['mime'],
            'file_size' => $fileData['size'],
        ]);

        Log::info('StoreTaskProofsJob: TaskProof record created', [
            'proof_id' => $proof->id,
            'task_response_id' => $taskResponse->id,
            'file_path' => $destinationPath,
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
