<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\FileValidatorInterface;
use App\Helpers\TaskProofPathGenerator;
use App\Models\Task;
use App\Models\TaskSharedProof;
use App\Services\FileValidation\FileValidationConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Job для асинхронной загрузки общих файлов задачи.
 *
 * Использует FileValidator для валидации MIME-типов.
 */
class StoreTaskSharedProofsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Имя диска для хранения файлов (унифицировано с TaskProof).
     */
    private const STORAGE_DISK = 'task_proofs';

    /**
     * Пресет валидации для доказательств задач.
     */
    private const VALIDATION_PRESET = 'task_proof';

    /**
     * @var array<string> Список путей сохранённых файлов для отката при ошибке
     */
    private array $storedFiles = [];

    /**
     * @param  int  $taskId  ID задачи
     * @param  array<array{path: string, original_name: string, mime: string, size: int}>  $filesData
     */
    public function __construct(
        public readonly int $taskId,
        public readonly array $filesData,
        public readonly int $dealershipId
    ) {
        $this->onQueue('shared_proof_upload');
    }

    public function handle(FileValidatorInterface $fileValidator, FileValidationConfig $config): void
    {
        $task = Task::find($this->taskId);

        if (! $task) {
            Log::warning('Task not found for shared proofs', ['task_id' => $this->taskId]);
            $this->cleanupTempFiles();

            return;
        }

        // Очистка ghost-записей (файлы не существуют на диске)
        $this->cleanupGhostRecords($task);

        // Проверка лимитов
        $limits = $config->getLimits();
        $existingCount = $task->sharedProofs()->count();
        $newCount = count($this->filesData);

        if ($existingCount + $newCount > $limits['max_files_per_response']) {
            Log::error('Too many shared proof files', [
                'task_id' => $this->taskId,
                'existing' => $existingCount,
                'new' => $newCount,
                'max' => $limits['max_files_per_response'],
            ]);
            $this->cleanupTempFiles();

            return;
        }

        DB::beginTransaction();
        try {
            $storedCount = 0;

            foreach ($this->filesData as $fileData) {
                $this->storeFile($task, $fileData, $fileValidator);
                $storedCount++;
            }

            DB::commit();
            Log::info('StoreTaskSharedProofsJob completed', [
                'task_id' => $this->taskId,
                'stored' => $storedCount,
                'total' => count($this->filesData),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            // Откат сохранённых файлов
            $this->rollbackStoredFiles();
            Log::error('StoreTaskSharedProofsJob failed with exception', [
                'task_id' => $this->taskId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        } finally {
            // Очистка temp-файлов независимо от результата
            $this->cleanupTempFiles();
        }
    }

    private function storeFile(Task $task, array $fileData, FileValidatorInterface $fileValidator): void
    {
        // Проверка существования temp файла
        if (! Storage::exists($fileData['path'])) {
            throw new \RuntimeException("Temp file not found: {$fileData['path']}");
        }

        // Валидация MIME типа через FileValidator
        $fileValidator->validateMimeType($fileData['mime'], self::VALIDATION_PRESET);

        // Генерация имени файла
        $extension = pathinfo($fileData['original_name'], PATHINFO_EXTENSION);
        $filename = sprintf(
            'shared_proof_%d_%d_%s.%s',
            time(),
            $task->id,
            bin2hex(random_bytes(8)),
            $extension
        );

        // Многоуровневая структура директорий (централизовано в TaskProofPathGenerator)
        $destinationPath = TaskProofPathGenerator::generatePath(
            $this->dealershipId,
            $task->id,
            $filename
        );

        // Получаем содержимое из temp
        $content = Storage::get($fileData['path']);
        if ($content === null) {
            throw new \RuntimeException("Failed to read temp file: {$fileData['path']}");
        }

        // Сохраняем на диск task_proofs
        if (! Storage::disk(self::STORAGE_DISK)->put($destinationPath, $content)) {
            throw new \RuntimeException("Failed to store file: {$destinationPath}");
        }

        // Добавляем в список для отката
        $this->storedFiles[] = $destinationPath;

        // Удаляем temp файл
        Storage::delete($fileData['path']);

        // Создаем запись
        TaskSharedProof::create([
            'task_id' => $task->id,
            'file_path' => $destinationPath,
            'original_filename' => $fileData['original_name'],
            'mime_type' => $fileData['mime'],
            'file_size' => $fileData['size'],
        ]);
    }

    /**
     * Удалить ghost-записи (DB-записи без файлов на диске).
     */
    private function cleanupGhostRecords(Task $task): void
    {
        $proofs = $task->sharedProofs()->get();

        foreach ($proofs as $proof) {
            // Проверяем на обоих дисках для обратной совместимости
            $existsOnTaskProofs = Storage::disk(self::STORAGE_DISK)->exists($proof->file_path);
            $existsOnLocal = Storage::disk('local')->exists($proof->file_path);

            if (! $existsOnTaskProofs && ! $existsOnLocal) {
                Log::warning('Removing ghost shared proof record', [
                    'proof_id' => $proof->id,
                    'task_id' => $task->id,
                    'file_path' => $proof->file_path,
                ]);
                $proof->delete();
            }
        }
    }

    /**
     * Откатить сохранённые файлы при ошибке.
     */
    private function rollbackStoredFiles(): void
    {
        foreach ($this->storedFiles as $filePath) {
            if (Storage::disk(self::STORAGE_DISK)->exists($filePath)) {
                Storage::disk(self::STORAGE_DISK)->delete($filePath);
                Log::info('StoreTaskSharedProofsJob: Rolled back stored file', [
                    'file_path' => $filePath,
                ]);
            }
        }
        $this->storedFiles = [];
    }

    /**
     * Очистить temp-файлы при неуспешной обработке.
     */
    private function cleanupTempFiles(): void
    {
        foreach ($this->filesData as $fileData) {
            if (Storage::exists($fileData['path'])) {
                Storage::delete($fileData['path']);
                Log::info('StoreTaskSharedProofsJob: Cleaned up temp file', [
                    'temp_path' => $fileData['path'],
                ]);
            }
        }
    }
}
