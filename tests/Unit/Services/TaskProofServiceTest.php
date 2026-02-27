<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Task;
use App\Models\TaskResponse;
use App\Models\TaskProof;
use App\Models\TaskSharedProof;
use App\Models\AutoDealership;
use App\Services\TaskProofService;
use App\Enums\Role;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Queue;

describe('TaskProofService', function () {
    beforeEach(function () {
        Storage::fake('task_proofs');
        Storage::fake('local');
        Queue::fake();

        $this->dealership = AutoDealership::factory()->create();
        $this->employee = User::factory()->create([
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $this->dealership->id
        ]);
        // Получаем сервис из контейнера с внедрёнными зависимостями
        $this->proofService = app(TaskProofService::class);
    });

    describe('storeProof', function () {
        it('stores a valid image file', function () {
            // Arrange
            $task = Task::factory()->create(['dealership_id' => $this->dealership->id]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending',
                'responded_at' => Carbon::now(),
            ]);

            $file = UploadedFile::fake()->image('photo.jpg', 800, 600);

            // Act
            $proof = $this->proofService->storeProof($response, $file, $this->dealership->id);

            // Assert
            expect($proof)->toBeInstanceOf(TaskProof::class);
            expect($proof->original_filename)->toBe('photo.jpg');
            expect($proof->mime_type)->toBe('image/jpeg');
            expect($proof->task_response_id)->toBe($response->id);
            Storage::disk('task_proofs')->assertExists($proof->file_path);
        });

        it('stores a pdf file', function () {
            // Arrange
            $task = Task::factory()->create(['dealership_id' => $this->dealership->id]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending',
                'responded_at' => Carbon::now(),
            ]);

            // Создаём временный файл с валидным PDF заголовком
            $pdfContent = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>%%EOF";
            $tempPath = sys_get_temp_dir() . '/test_document.pdf';
            file_put_contents($tempPath, $pdfContent);
            $file = new \Illuminate\Http\UploadedFile($tempPath, 'document.pdf', 'application/pdf', null, true);

            // Act
            $proof = $this->proofService->storeProof($response, $file, $this->dealership->id);

            // Assert
            expect($proof->original_filename)->toBe('document.pdf');

            // Cleanup
            @unlink($tempPath);
        });

        it('throws exception for invalid extension', function () {
            // Arrange
            $task = Task::factory()->create(['dealership_id' => $this->dealership->id]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending',
                'responded_at' => Carbon::now(),
            ]);

            $file = UploadedFile::fake()->create('malicious.exe', 100, 'application/x-msdownload');

            // Act & Assert
            expect(fn () => $this->proofService->storeProof($response, $file, $this->dealership->id))
                ->toThrow(InvalidArgumentException::class);
        });

        it('throws exception for file exceeding size limit', function () {
            // Arrange
            $task = Task::factory()->create(['dealership_id' => $this->dealership->id]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending',
                'responded_at' => Carbon::now(),
            ]);

            // Image > 5MB
            $file = UploadedFile::fake()->image('large_photo.jpg')->size(6000);

            // Act & Assert
            expect(fn () => $this->proofService->storeProof($response, $file, $this->dealership->id))
                ->toThrow(InvalidArgumentException::class);
        });
    });

    describe('storeProofs', function () {
        it('stores multiple files', function () {
            // Arrange
            $task = Task::factory()->create(['dealership_id' => $this->dealership->id]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending',
                'responded_at' => Carbon::now(),
            ]);

            $files = [
                UploadedFile::fake()->image('photo1.jpg', 800, 600),
                UploadedFile::fake()->image('photo2.png', 800, 600),
            ];

            // Act
            $proofs = $this->proofService->storeProofs($response, $files, $this->dealership->id);

            // Assert
            expect($proofs)->toHaveCount(2);
        });

        it('throws exception when exceeding max files', function () {
            // Arrange
            $task = Task::factory()->create(['dealership_id' => $this->dealership->id]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending',
                'responded_at' => Carbon::now(),
            ]);

            // Pre-create 3 proofs
            TaskProof::factory(3)->create(['task_response_id' => $response->id]);

            $files = [
                UploadedFile::fake()->image('photo1.jpg', 800, 600),
                UploadedFile::fake()->image('photo2.jpg', 800, 600),
                UploadedFile::fake()->image('photo3.jpg', 800, 600),
            ];

            // Act & Assert - 3 existing + 3 new = 6 > 5 max
            expect(fn () => $this->proofService->storeProofs($response, $files, $this->dealership->id))
                ->toThrow(InvalidArgumentException::class);
        });

        it('rolls back on failure', function () {
            // Arrange
            $task = Task::factory()->create(['dealership_id' => $this->dealership->id]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending',
                'responded_at' => Carbon::now(),
            ]);

            $files = [
                UploadedFile::fake()->image('photo1.jpg', 800, 600),
                UploadedFile::fake()->create('invalid.exe', 100, 'application/x-msdownload'), // Invalid
            ];

            // Act & Assert
            try {
                $this->proofService->storeProofs($response, $files, $this->dealership->id);
            } catch (InvalidArgumentException) {
                // Should have rolled back - no proofs saved
                expect(TaskProof::where('task_response_id', $response->id)->count())->toBe(0);
            }
        });
    });

    describe('deleteProof', function () {
        it('deletes proof and dispatches file deletion job', function () {
            // Arrange
            $task = Task::factory()->create(['dealership_id' => $this->dealership->id]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending',
                'responded_at' => Carbon::now(),
            ]);

            $file = UploadedFile::fake()->image('photo.jpg', 800, 600);
            $proof = $this->proofService->storeProof($response, $file, $this->dealership->id);
            $proofId = $proof->id;

            // Act
            $this->proofService->deleteProof($proof);

            // Assert
            $this->assertDatabaseMissing('task_proofs', ['id' => $proofId]);
            Queue::assertPushed(\App\Jobs\DeleteProofFileJob::class);
        });
    });

    describe('deleteAllProofs', function () {
        it('deletes all proofs for a response', function () {
            // Arrange
            $task = Task::factory()->create(['dealership_id' => $this->dealership->id]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending',
                'responded_at' => Carbon::now(),
            ]);

            $file1 = UploadedFile::fake()->image('photo1.jpg', 800, 600);
            $file2 = UploadedFile::fake()->image('photo2.jpg', 800, 600);

            $this->proofService->storeProof($response, $file1, $this->dealership->id);
            $this->proofService->storeProof($response, $file2, $this->dealership->id);

            $response->load('proofs');

            // Act
            $this->proofService->deleteAllProofs($response);

            // Assert
            expect(TaskProof::where('task_response_id', $response->id)->count())->toBe(0);
        });
    });

    describe('deleteSharedProof', function () {
        it('deletes shared proof and dispatches file deletion job', function () {
            // Arrange
            $task = Task::factory()->create(['dealership_id' => $this->dealership->id]);
            $sharedProof = TaskSharedProof::factory()->create([
                'task_id' => $task->id,
                'file_path' => 'shared/test.jpg',
            ]);
            $proofId = $sharedProof->id;

            // Act
            $this->proofService->deleteSharedProof($sharedProof);

            // Assert
            $this->assertDatabaseMissing('task_shared_proofs', ['id' => $proofId]);
            Queue::assertPushed(\App\Jobs\DeleteProofFileJob::class);
        });
    });

    describe('getFilePath', function () {
        it('returns file path when file exists', function () {
            // Arrange
            $task = Task::factory()->create(['dealership_id' => $this->dealership->id]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending',
                'responded_at' => Carbon::now(),
            ]);

            $file = UploadedFile::fake()->image('photo.jpg', 800, 600);
            $proof = $this->proofService->storeProof($response, $file, $this->dealership->id);

            // Act
            $filePath = $this->proofService->getFilePath($proof);

            // Assert
            expect($filePath)->not->toBeNull();
            expect(file_exists($filePath))->toBeTrue();
        });

        it('returns null when file does not exist', function () {
            // Arrange
            $proof = new TaskProof(['file_path' => 'non_existent_file.jpg']);

            // Act
            $filePath = $this->proofService->getFilePath($proof);

            // Assert
            expect($filePath)->toBeNull();
        });
    });

    describe('fileExists', function () {
        it('returns true when file exists', function () {
            // Arrange
            $task = Task::factory()->create(['dealership_id' => $this->dealership->id]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending',
                'responded_at' => Carbon::now(),
            ]);

            $file = UploadedFile::fake()->image('photo.jpg', 800, 600);
            $proof = $this->proofService->storeProof($response, $file, $this->dealership->id);

            // Act & Assert
            expect($this->proofService->fileExists($proof))->toBeTrue();
        });

        it('returns false when file does not exist', function () {
            // Arrange
            $proof = new TaskProof(['file_path' => 'non_existent_file.jpg']);

            // Act & Assert
            expect($this->proofService->fileExists($proof))->toBeFalse();
        });
    });

    describe('getAllowedExtensions', function () {
        it('returns array of allowed extensions', function () {
            // Act - теперь это instance метод
            $extensions = $this->proofService->getAllowedExtensions();

            // Assert
            expect($extensions)->toBeArray();
            expect($extensions)->toContain('jpg', 'png', 'pdf', 'mp4');
        });
    });

    describe('getAllowedMimeTypes', function () {
        it('returns array of allowed mime types', function () {
            // Act - теперь это instance метод
            $mimeTypes = $this->proofService->getAllowedMimeTypes();

            // Assert
            expect($mimeTypes)->toBeArray();
            expect($mimeTypes)->toContain('image/jpeg', 'image/png', 'application/pdf');
        });
    });

    describe('getStorageDisk', function () {
        it('returns storage disk name', function () {
            // Act - статический метод остался
            $disk = TaskProofService::getStorageDisk();

            // Assert
            expect($disk)->toBe('task_proofs');
        });
    });
});
