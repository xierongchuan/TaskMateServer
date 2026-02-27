<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

describe('CleanupTempProofUploads Command', function () {
    beforeEach(function () {
        Storage::fake();
    });

    it('завершается успешно если директория не существует', function () {
        // Act & Assert
        $this->artisan('proofs:cleanup-temp')
            ->expectsOutput('Директория temp/proof_uploads не существует')
            ->assertExitCode(Command::SUCCESS);
    });

    it('удаляет файлы старше 24 часов', function () {
        // Arrange
        $filePath = 'temp/proof_uploads/old_file.jpg';
        Storage::put($filePath, 'old content');

        // Бэкдатируем файл через touch на fake disk
        $fullPath = Storage::path($filePath);
        touch($fullPath, now()->subHours(25)->timestamp);

        // Act
        $this->artisan('proofs:cleanup-temp')
            ->expectsOutput('Удалено 1 временных файлов')
            ->assertExitCode(Command::SUCCESS);

        // Assert
        Storage::assertMissing($filePath);
    });

    it('не удаляет файлы младше 24 часов', function () {
        // Arrange
        $filePath = 'temp/proof_uploads/recent_file.jpg';
        Storage::put($filePath, 'recent content');

        // Act
        $this->artisan('proofs:cleanup-temp')
            ->expectsOutput('Удалено 0 временных файлов')
            ->assertExitCode(Command::SUCCESS);

        // Assert
        Storage::assertExists($filePath);
    });

    it('удаляет только старые файлы из смешанного набора', function () {
        // Arrange
        $oldFile1 = 'temp/proof_uploads/old1.jpg';
        $oldFile2 = 'temp/proof_uploads/old2.pdf';
        $recentFile = 'temp/proof_uploads/recent.png';

        Storage::put($oldFile1, 'old1');
        Storage::put($oldFile2, 'old2');
        Storage::put($recentFile, 'recent');

        // Бэкдатируем старые файлы
        touch(Storage::path($oldFile1), now()->subHours(30)->timestamp);
        touch(Storage::path($oldFile2), now()->subHours(48)->timestamp);

        // Act
        $this->artisan('proofs:cleanup-temp')
            ->expectsOutput('Удалено 2 временных файлов')
            ->assertExitCode(Command::SUCCESS);

        // Assert
        Storage::assertMissing($oldFile1);
        Storage::assertMissing($oldFile2);
        Storage::assertExists($recentFile);
    });

    it('возвращает код SUCCESS', function () {
        // Arrange
        Storage::makeDirectory('temp/proof_uploads');

        // Act & Assert
        $this->artisan('proofs:cleanup-temp')
            ->assertExitCode(Command::SUCCESS);
    });
});
