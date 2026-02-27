<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Команда для очистки временных файлов загрузки доказательств.
 *
 * Удаляет файлы старше 24 часов из директории temp/proof_uploads.
 * Такие файлы могут остаться, если Job не обработался или произошла ошибка.
 */
class CleanupTempProofUploads extends Command
{
    protected $signature = 'proofs:cleanup-temp';

    protected $description = 'Удалить временные файлы загрузки доказательств старше 24 часов';

    public function handle(): int
    {
        $tempPath = 'temp/proof_uploads';

        if (!Storage::exists($tempPath)) {
            $this->info('Директория temp/proof_uploads не существует');

            return Command::SUCCESS;
        }

        $files = Storage::files($tempPath);
        $cutoff = now()->subHours(24);
        $deleted = 0;

        foreach ($files as $file) {
            $lastModified = Storage::lastModified($file);

            if ($lastModified < $cutoff->timestamp) {
                Storage::delete($file);
                $deleted++;
            }
        }

        $this->info("Удалено {$deleted} временных файлов");

        return Command::SUCCESS;
    }
}
