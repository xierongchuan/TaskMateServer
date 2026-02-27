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
        if (Storage::disk($this->disk)->exists($this->filePath)) {
            Storage::disk($this->disk)->delete($this->filePath);
            Log::info("DeleteProofFileJob: удалён файл {$this->filePath} с диска {$this->disk}");
        } else {
            Log::warning("DeleteProofFileJob: файл {$this->filePath} не найден на диске {$this->disk}");
        }
    }
}
