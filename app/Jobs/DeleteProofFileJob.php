<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Job для асинхронного удаления файлов доказательств из хранилища.
 */
class DeleteProofFileJob implements ShouldQueue
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

    public function __construct(
        private readonly string $filePath,
        private readonly string $disk,
    ) {
        $this->onQueue('file_cleanup');
    }

    public function handle(): void
    {
        try {
            if (Storage::disk($this->disk)->exists($this->filePath)) {
                if (Storage::disk($this->disk)->delete($this->filePath)) {
                    Log::info('DeleteProofFileJob: File deleted successfully', [
                        'file_path' => $this->filePath,
                        'disk' => $this->disk,
                    ]);
                } else {
                    Log::error('DeleteProofFileJob: Failed to delete file', [
                        'file_path' => $this->filePath,
                        'disk' => $this->disk,
                    ]);
                    throw new \RuntimeException("Failed to delete file: {$this->filePath}");
                }
            } else {
                Log::warning('DeleteProofFileJob: File not found', [
                    'file_path' => $this->filePath,
                    'disk' => $this->disk,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('DeleteProofFileJob: Unexpected error during deletion', [
                'file_path' => $this->filePath,
                'disk' => $this->disk,
                'error' => $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null,
            ]);
            throw $e;
        }
    }
}
