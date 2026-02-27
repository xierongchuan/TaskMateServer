<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\FileValidatorInterface;
use App\Jobs\DeleteProofFileJob;
use App\Jobs\StoreTaskProofsJob;
use App\Models\TaskProof;
use App\Models\TaskResponse;
use App\Services\FileValidation\FileValidationConfig;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Сервис для работы с доказательствами выполнения задач.
 *
 * Файлы хранятся в приватном хранилище и доступны только через
 * подписанные URL с проверкой авторизации.
 *
 * Single Responsibility: только хранение и управление файлами доказательств.
 * Валидация делегируется FileValidatorInterface.
 */
class TaskProofService
{
    /**
     * Имя диска для хранения файлов.
     */
    private const STORAGE_DISK = 'task_proofs';

    /**
     * Пресет валидации для доказательств задач.
     */
    private const VALIDATION_PRESET = 'task_proof';

    public function __construct(
        private readonly FileValidatorInterface $fileValidator,
        private readonly FileValidationConfig $config
    ) {}

    /**
     * Сохранить файл доказательства.
     *
     * @param TaskResponse $response Ответ на задачу
     * @param UploadedFile $file Загружаемый файл
     * @param int $dealershipId ID автосалона
     * @return TaskProof
     * @throws InvalidArgumentException
     */
    public function storeProof(
        TaskResponse $response,
        UploadedFile $file,
        int $dealershipId
    ): TaskProof {
        $this->fileValidator->validate($file, self::VALIDATION_PRESET);

        $path = $this->generateFilePath($response->task_id, $dealershipId);
        $filename = $this->generateFilename($file, $response->user_id);

        $storedPath = $file->storeAs($path, $filename, self::STORAGE_DISK);

        if ($storedPath === false) {
            throw new InvalidArgumentException('Не удалось сохранить файл');
        }

        $mimeType = $this->fileValidator->resolveMimeType($file);

        return TaskProof::create([
            'task_response_id' => $response->id,
            'file_path' => $storedPath,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $mimeType,
            'file_size' => $file->getSize(),
        ]);
    }

    /**
     * Сохранить несколько файлов доказательств с транзакцией.
     *
     * При ошибке загрузки любого файла все уже загруженные файлы
     * будут удалены, а транзакция БД откачена.
     *
     * @param TaskResponse $response Ответ на задачу
     * @param array<UploadedFile> $files Массив загружаемых файлов
     * @param int $dealershipId ID автосалона
     * @return array<TaskProof>
     * @throws InvalidArgumentException
     */
    public function storeProofs(
        TaskResponse $response,
        array $files,
        int $dealershipId
    ): array {
        $limits = $this->config->getLimits();

        // Проверяем количество файлов
        $existingCount = $response->proofs()->count();
        $newCount = count($files);

        if ($existingCount + $newCount > $limits['max_files_per_response']) {
            throw new InvalidArgumentException(
                sprintf(
                    'Превышено максимальное количество файлов. Максимум: %d, уже загружено: %d, новых: %d',
                    $limits['max_files_per_response'],
                    $existingCount,
                    $newCount
                )
            );
        }

        // Проверяем общий размер
        $existingSize = $response->proofs()->sum('file_size');
        $newSize = array_reduce($files, fn ($carry, $file) => $carry + $file->getSize(), 0);

        if ($existingSize + $newSize > $limits['max_total_size']) {
            throw new InvalidArgumentException(
                sprintf(
                    'Превышен максимальный общий размер файлов. Максимум: %d MB',
                    $limits['max_total_size'] / 1024 / 1024
                )
            );
        }

        // Предварительная валидация всех файлов перед загрузкой
        foreach ($files as $file) {
            $this->fileValidator->validate($file, self::VALIDATION_PRESET);
        }

        // Загрузка файлов с транзакцией и откатом при ошибке
        $storedPaths = [];
        $proofs = [];

        try {
            DB::beginTransaction();

            foreach ($files as $file) {
                $path = $this->generateFilePath($response->task_id, $dealershipId);
                $filename = $this->generateFilename($file, $response->user_id);

                $storedPath = $file->storeAs($path, $filename, self::STORAGE_DISK);

                if ($storedPath === false) {
                    throw new InvalidArgumentException('Не удалось сохранить файл: ' . $file->getClientOriginalName());
                }

                $storedPaths[] = $storedPath;

                $mimeType = $this->fileValidator->resolveMimeType($file);

                $proofs[] = TaskProof::create([
                    'task_response_id' => $response->id,
                    'file_path' => $storedPath,
                    'original_filename' => $file->getClientOriginalName(),
                    'mime_type' => $mimeType,
                    'file_size' => $file->getSize(),
                ]);
            }

            DB::commit();

            return $proofs;
        } catch (\Throwable $e) {
            DB::rollBack();

            // Удаляем все уже загруженные файлы
            foreach ($storedPaths as $storedPath) {
                Storage::disk(self::STORAGE_DISK)->delete($storedPath);
            }

            throw $e;
        }
    }

    /**
     * Сохранить файлы доказательств асинхронно (через очередь).
     *
     * Валидация выполняется синхронно — ошибки возвращаются сразу.
     * Файлы сохраняются во временное хранилище и передаются в Job.
     *
     * @param TaskResponse $response Ответ на задачу
     * @param array<UploadedFile> $files Массив загружаемых файлов
     * @param int $dealershipId ID автосалона
     * @throws InvalidArgumentException
     */
    public function storeProofsAsync(
        TaskResponse $response,
        array $files,
        int $dealershipId
    ): void {
        $limits = $this->config->getLimits();

        // Проверяем количество файлов
        $existingCount = $response->proofs()->count();
        $newCount = count($files);

        if ($existingCount + $newCount > $limits['max_files_per_response']) {
            throw new InvalidArgumentException(
                sprintf(
                    'Превышено максимальное количество файлов. Максимум: %d, уже загружено: %d, новых: %d',
                    $limits['max_files_per_response'],
                    $existingCount,
                    $newCount
                )
            );
        }

        // Проверяем общий размер
        $existingSize = $response->proofs()->sum('file_size');
        $newSize = array_reduce($files, fn ($carry, $file) => $carry + $file->getSize(), 0);

        if ($existingSize + $newSize > $limits['max_total_size']) {
            throw new InvalidArgumentException(
                sprintf(
                    'Превышен максимальный общий размер файлов. Максимум: %d MB',
                    $limits['max_total_size'] / 1024 / 1024
                )
            );
        }

        // Валидация всех файлов (синхронно — пользователь получает ошибку сразу)
        foreach ($files as $file) {
            $this->fileValidator->validate($file, self::VALIDATION_PRESET);
        }

        // Сохранение во временное хранилище
        $filesData = [];
        foreach ($files as $file) {
            $tempPath = $file->store('temp/proof_uploads');

            if ($tempPath === false) {
                // Очищаем уже сохранённые temp файлы
                foreach ($filesData as $data) {
                    Storage::delete($data['path']);
                }
                throw new InvalidArgumentException('Не удалось сохранить файл во временное хранилище');
            }

            $mimeType = $this->fileValidator->resolveMimeType($file);

            $filesData[] = [
                'path' => $tempPath,
                'original_name' => $file->getClientOriginalName(),
                'mime' => $mimeType,
                'size' => $file->getSize(),
                'user_id' => $response->user_id,
            ];
        }

        // Dispatch job
        StoreTaskProofsJob::dispatch(
            $response->id,
            $filesData,
            $dealershipId,
            $response->task_id
        );
    }

    /**
     * Удалить файл доказательства.
     *
     * @param TaskProof $proof Доказательство для удаления
     */
    public function deleteProof(TaskProof $proof): void
    {
        $filePath = $proof->file_path;
        $proof->delete();
        DeleteProofFileJob::dispatch($filePath, self::STORAGE_DISK);
    }

    /**
     * Удалить все доказательства для ответа.
     *
     * @param TaskResponse $response Ответ на задачу
     */
    public function deleteAllProofs(TaskResponse $response): void
    {
        foreach ($response->proofs as $proof) {
            $this->deleteProof($proof);
        }
    }

    /**
     * Удалить один общий файл задачи (shared_proof).
     *
     * Поддерживает обратную совместимость: файлы могут быть на диске
     * task_proofs (новая структура) или local (старая структура).
     *
     * @param \App\Models\TaskSharedProof $proof Общий файл задачи
     */
    public function deleteSharedProof(\App\Models\TaskSharedProof $proof): void
    {
        $filePath = $proof->file_path;
        $proof->delete();

        // Определяем диск для удаления (новая структура или старая)
        if (Storage::disk(self::STORAGE_DISK)->exists($filePath)) {
            DeleteProofFileJob::dispatch($filePath, self::STORAGE_DISK);
        } else {
            // Обратная совместимость со старой структурой
            DeleteProofFileJob::dispatch($filePath, 'local');
        }
    }

    /**
     * Удалить все общие файлы задачи (shared_proofs).
     *
     * Используется при отклонении групповой задачи с complete_for_all.
     *
     * @param \App\Models\Task $task Задача
     */
    public function deleteSharedProofs(\App\Models\Task $task): void
    {
        foreach ($task->sharedProofs as $proof) {
            $this->deleteSharedProof($proof);
        }
    }

    /**
     * Получить полный путь к файлу на диске.
     *
     * @param TaskProof $proof Доказательство
     * @return string|null Путь или null если файл не существует
     */
    public function getFilePath(TaskProof $proof): ?string
    {
        if (!Storage::disk(self::STORAGE_DISK)->exists($proof->file_path)) {
            return null;
        }

        return Storage::disk(self::STORAGE_DISK)->path($proof->file_path);
    }

    /**
     * Проверить существование файла.
     *
     * @param TaskProof $proof Доказательство
     * @return bool
     */
    public function fileExists(TaskProof $proof): bool
    {
        return Storage::disk(self::STORAGE_DISK)->exists($proof->file_path);
    }

    /**
     * Генерация пути для хранения файла.
     */
    private function generateFilePath(int $taskId, int $dealershipId): string
    {
        $date = date('Y/m/d');

        return sprintf(
            'dealerships/%d/tasks/%d/%s',
            $dealershipId,
            $taskId,
            $date
        );
    }

    /**
     * Генерация имени файла.
     */
    private function generateFilename(UploadedFile $file, int $userId): string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $timestamp = time();
        $random = bin2hex(random_bytes(8));

        return sprintf('proof_%d_%d_%s.%s', $timestamp, $userId, $random, $extension);
    }

    /**
     * Получить максимальное количество файлов на ответ.
     *
     * @return int
     */
    public function getMaxFilesPerResponse(): int
    {
        return $this->config->getMaxFilesPerResponse();
    }

    /**
     * Получить максимальный общий размер файлов.
     *
     * @return int Размер в байтах
     */
    public function getMaxTotalSize(): int
    {
        return $this->config->getMaxTotalSize();
    }

    /**
     * Получить список разрешённых расширений.
     *
     * @return array<string>
     */
    public function getAllowedExtensions(): array
    {
        return $this->fileValidator->getAllowedExtensions(self::VALIDATION_PRESET);
    }

    /**
     * Получить список разрешённых MIME-типов.
     *
     * @return array<string>
     */
    public function getAllowedMimeTypes(): array
    {
        return $this->fileValidator->getAllowedMimeTypes(self::VALIDATION_PRESET);
    }

    /**
     * Получить имя диска хранилища.
     *
     * @return string
     */
    public static function getStorageDisk(): string
    {
        return self::STORAGE_DISK;
    }

    /**
     * Статический геттер максимального количества файлов.
     *
     * Используется в правилах валидации контроллеров, где DI недоступен.
     *
     * @return int
     */
    public static function getMaxFilesLimit(): int
    {
        return config('file_upload.limits.max_files_per_response', 5);
    }

    /**
     * Статическая константа для обратной совместимости.
     *
     * @deprecated Использовать getMaxFilesLimit() или instance метод getMaxFilesPerResponse()
     */
    public const MAX_FILES_PER_RESPONSE = 5;
}
