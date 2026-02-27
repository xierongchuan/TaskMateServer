<?php

declare(strict_types=1);

use App\Contracts\FileValidatorInterface;
use App\Enums\Role;
use App\Jobs\StoreTaskSharedProofsJob;
use App\Models\AutoDealership;
use App\Models\Task;
use App\Models\TaskSharedProof;
use App\Models\User;
use App\Services\FileValidation\FileValidationConfig;
use Illuminate\Support\Facades\Storage;

describe('StoreTaskSharedProofsJob', function () {
    beforeEach(function () {
        Storage::fake();
        Storage::fake('task_proofs');
        Storage::fake('local');

        $this->dealership = AutoDealership::factory()->create();
        $this->manager = User::factory()->create([
            'role' => Role::MANAGER->value,
            'dealership_id' => $this->dealership->id,
        ]);
        $this->task = Task::factory()->group()->create([
            'dealership_id' => $this->dealership->id,
            'creator_id' => $this->manager->id,
        ]);
    });

    describe('конфигурация', function () {
        it('использует очередь shared_proof_upload', function () {
            $job = new StoreTaskSharedProofsJob(1, [], 1);

            expect($job->queue)->toBe('shared_proof_upload');
        });
    });

    describe('handle', function () {
        it('сохраняет файлы и создаёт записи TaskSharedProof', function () {
            // Arrange
            $tempPath = 'temp/proof_uploads/shared_test.jpg';
            Storage::put($tempPath, 'fake-image-content');

            $filesData = [[
                'path' => $tempPath,
                'original_name' => 'group_photo.jpg',
                'mime' => 'image/jpeg',
                'size' => 12345,
            ]];

            $job = new StoreTaskSharedProofsJob(
                $this->task->id,
                $filesData,
                $this->dealership->id,
            );

            // Act
            $job->handle(app(FileValidatorInterface::class), app(FileValidationConfig::class));

            // Assert
            expect(TaskSharedProof::count())->toBe(1);

            $proof = TaskSharedProof::first();
            expect($proof->task_id)->toBe($this->task->id);
            expect($proof->original_filename)->toBe('group_photo.jpg');
            expect($proof->mime_type)->toBe('image/jpeg');
            expect($proof->file_size)->toBe(12345);

            Storage::disk('task_proofs')->assertExists($proof->file_path);
            Storage::assertMissing($tempPath);
        });

        it('очищает temp файлы если задача не найдена', function () {
            // Arrange
            $tempPath = 'temp/proof_uploads/orphan_shared.jpg';
            Storage::put($tempPath, 'content');

            $job = new StoreTaskSharedProofsJob(
                99999, // несуществующий ID
                [['path' => $tempPath, 'original_name' => 'orphan.jpg', 'mime' => 'image/jpeg', 'size' => 100]],
                $this->dealership->id,
            );

            // Act
            $job->handle(app(FileValidatorInterface::class), app(FileValidationConfig::class));

            // Assert
            expect(TaskSharedProof::count())->toBe(0);
            Storage::assertMissing($tempPath);
        });

        it('отклоняет при превышении лимита файлов', function () {
            // Arrange — создаём существующие proof записи до лимита
            $config = app(FileValidationConfig::class);
            $maxFiles = $config->getLimits()['max_files_per_response'];

            // Заполняем до лимита и создаём реальные файлы чтобы ghost-cleanup не удалил
            for ($i = 0; $i < $maxFiles; $i++) {
                $filePath = "dealerships/{$this->dealership->id}/tasks/{$this->task->id}/existing_{$i}.jpg";
                Storage::disk('task_proofs')->put($filePath, "content-{$i}");
                TaskSharedProof::create([
                    'task_id' => $this->task->id,
                    'file_path' => $filePath,
                    'original_filename' => "existing_{$i}.jpg",
                    'mime_type' => 'image/jpeg',
                    'file_size' => 1000,
                ]);
            }

            $tempPath = 'temp/proof_uploads/over_limit.jpg';
            Storage::put($tempPath, 'content');

            $job = new StoreTaskSharedProofsJob(
                $this->task->id,
                [['path' => $tempPath, 'original_name' => 'over.jpg', 'mime' => 'image/jpeg', 'size' => 100]],
                $this->dealership->id,
            );

            // Act
            $job->handle(app(FileValidatorInterface::class), $config);

            // Assert — новые не добавились
            expect(TaskSharedProof::where('task_id', $this->task->id)->count())->toBe($maxFiles);
            Storage::assertMissing($tempPath);
        });

        it('удаляет ghost-записи перед сохранением', function () {
            // Arrange — создаём DB-запись без файла на диске
            $ghostProof = TaskSharedProof::create([
                'task_id' => $this->task->id,
                'file_path' => 'nonexistent/path/ghost.jpg',
                'original_filename' => 'ghost.jpg',
                'mime_type' => 'image/jpeg',
                'file_size' => 100,
            ]);

            $tempPath = 'temp/proof_uploads/real_file.jpg';
            Storage::put($tempPath, 'real-content');

            $job = new StoreTaskSharedProofsJob(
                $this->task->id,
                [['path' => $tempPath, 'original_name' => 'real.jpg', 'mime' => 'image/jpeg', 'size' => 200]],
                $this->dealership->id,
            );

            // Act
            $job->handle(app(FileValidatorInterface::class), app(FileValidationConfig::class));

            // Assert — ghost удалён, новый создан
            expect(TaskSharedProof::where('id', $ghostProof->id)->exists())->toBeFalse();
            expect(TaskSharedProof::where('task_id', $this->task->id)->count())->toBe(1);
        });

        it('продолжает при ошибке одного файла из нескольких', function () {
            // Arrange
            $tempPath1 = 'temp/proof_uploads/good.jpg';
            Storage::put($tempPath1, 'good-content');

            // Второй файл не существует в temp
            $filesData = [
                ['path' => $tempPath1, 'original_name' => 'good.jpg', 'mime' => 'image/jpeg', 'size' => 100],
                ['path' => 'temp/proof_uploads/bad.jpg', 'original_name' => 'bad.jpg', 'mime' => 'image/jpeg', 'size' => 100],
            ];

            $job = new StoreTaskSharedProofsJob(
                $this->task->id,
                $filesData,
                $this->dealership->id,
            );

            // Act
            $job->handle(app(FileValidatorInterface::class), app(FileValidationConfig::class));

            // Assert — один файл сохранён
            expect(TaskSharedProof::where('task_id', $this->task->id)->count())->toBe(1);
        });

        it('генерирует путь с shared_proof_ префиксом', function () {
            // Arrange
            $tempPath = 'temp/proof_uploads/shared.jpg';
            Storage::put($tempPath, 'content');

            $job = new StoreTaskSharedProofsJob(
                $this->task->id,
                [['path' => $tempPath, 'original_name' => 'shared.jpg', 'mime' => 'image/jpeg', 'size' => 100]],
                $this->dealership->id,
            );

            // Act
            $job->handle(app(FileValidatorInterface::class), app(FileValidationConfig::class));

            // Assert
            $proof = TaskSharedProof::first();
            $expectedPrefix = sprintf(
                'dealerships/%d/tasks/%d/%s/',
                $this->dealership->id,
                $this->task->id,
                date('Y/m/d'),
            );
            expect($proof->file_path)->toStartWith($expectedPrefix);
            expect($proof->file_path)->toContain('shared_proof_');
        });
    });
});
