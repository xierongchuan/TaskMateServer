<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\AutoDealership;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskProof;
use App\Models\TaskResponse;
use App\Models\User;
use App\Services\TaskProofService;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

describe('TaskProofService Atomic Operations', function () {
    beforeEach(function () {
        Storage::fake('task_proofs');
        $this->dealership = AutoDealership::factory()->create();
        $this->manager = User::factory()->create([
            'role' => Role::MANAGER->value,
            'dealership_id' => $this->dealership->id,
        ]);
        $this->employee = User::factory()->create([
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $this->dealership->id,
        ]);
        $this->proofService = app(TaskProofService::class);
    });

    describe('Single File Operations', function () {
        it('stores proof file atomically', function () {
            // Arrange
            $task = Task::factory()->completionWithProof()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);
            $file = UploadedFile::fake()->image('proof.jpg', 100, 100);

            // Act
            $proof = $this->proofService->storeProof($response, $file, $this->dealership->id);

            // Assert: Both DB record and file exist
            expect($proof)->not->toBeNull();
            expect(TaskProof::find($proof->id))->not->toBeNull();
            expect($this->proofService->fileExists($proof))->toBeTrue();
        });

        it('deletes proof via job dispatch', function () {
            // Arrange
            $task = Task::factory()->completionWithProof()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);
            $file = UploadedFile::fake()->image('proof.jpg', 100, 100);
            $proof = $this->proofService->storeProof($response, $file, $this->dealership->id);

            $proofId = $proof->id;

            // Act
            $this->proofService->deleteProof($proof);

            // Assert: DB record deleted
            expect(TaskProof::find($proofId))->toBeNull();
        });
    });

    describe('Multi-File Operations', function () {
        it('stores multiple proofs atomically', function () {
            // Arrange
            $task = Task::factory()->completionWithProof()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);
            $files = [
                UploadedFile::fake()->image('proof1.jpg', 100, 100),
                UploadedFile::fake()->image('proof2.jpg', 100, 100),
                UploadedFile::fake()->image('proof3.jpg', 100, 100),
            ];

            // Act
            $proofs = $this->proofService->storeProofs($response, $files, $this->dealership->id);

            // Assert: All 3 proofs created
            expect($proofs)->toHaveCount(3);
            expect(TaskProof::where('task_response_id', $response->id)->count())->toBe(3);
        });

        it('enforces max files limit', function () {
            // Arrange
            $task = Task::factory()->completionWithProof()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);

            // Create 6 files (max is 5)
            $files = [];
            for ($i = 0; $i < 6; $i++) {
                $files[] = UploadedFile::fake()->image("proof{$i}.jpg", 100, 100);
            }

            // Act & Assert
            expect(fn () => $this->proofService->storeProofs($response, $files, $this->dealership->id))
                ->toThrow(\InvalidArgumentException::class);
        });

        it('deletes all proofs for response', function () {
            // Arrange
            $task = Task::factory()->completionWithProof()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);
            $files = [
                UploadedFile::fake()->image('proof1.jpg', 100, 100),
                UploadedFile::fake()->image('proof2.jpg', 100, 100),
            ];
            $this->proofService->storeProofs($response, $files, $this->dealership->id);

            expect(TaskProof::where('task_response_id', $response->id)->count())->toBe(2);

            // Act
            $this->proofService->deleteAllProofs($response);

            // Assert: All proofs deleted
            expect(TaskProof::where('task_response_id', $response->id)->count())->toBe(0);
        });
    });

    describe('File Validation', function () {
        it('validates allowed extensions', function () {
            // Arrange
            $task = Task::factory()->completionWithProof()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);

            // Create file with invalid extension
            $file = UploadedFile::fake()->create('malware.exe', 100);

            // Act & Assert
            expect(fn () => $this->proofService->storeProof($response, $file, $this->dealership->id))
                ->toThrow(\InvalidArgumentException::class);
        });

        it('accepts valid image formats', function () {
            $task = Task::factory()->completionWithProof()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);

            // Note: webp requires imagewebp() function which may not be available in all PHP builds
            $validFormats = ['jpg', 'jpeg', 'png', 'gif'];

            foreach ($validFormats as $format) {
                $file = UploadedFile::fake()->image("test.{$format}", 100, 100);
                $proof = $this->proofService->storeProof($response, $file, $this->dealership->id);
                expect($proof)->not->toBeNull();
            }
        });

        it('accepts PDF files', function () {
            $task = Task::factory()->completionWithProof()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);

            // Создаём реальный PDF файл с корректной сигнатурой
            $pdfContent = '%PDF-1.4 fake pdf content for testing';
            $tempFile = tempnam(sys_get_temp_dir(), 'test_pdf_');
            file_put_contents($tempFile, $pdfContent);
            $file = new UploadedFile($tempFile, 'document.pdf', 'application/pdf', null, true);
            $proof = $this->proofService->storeProof($response, $file, $this->dealership->id);
            expect($proof)->not->toBeNull();
            expect($proof->mime_type)->toBe('application/pdf');
        });
    });

    describe('File Path Generation', function () {
        it('generates correct file path structure', function () {
            $task = Task::factory()->completionWithProof()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);
            $file = UploadedFile::fake()->image('proof.jpg', 100, 100);

            $proof = $this->proofService->storeProof($response, $file, $this->dealership->id);

            // Assert: Path contains dealership and task IDs
            expect($proof->file_path)->toContain("dealerships/{$this->dealership->id}");
            expect($proof->file_path)->toContain("tasks/{$task->id}");
        });

        it('preserves original filename', function () {
            $task = Task::factory()->completionWithProof()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);
            $file = UploadedFile::fake()->image('my_original_photo.jpg', 100, 100);

            $proof = $this->proofService->storeProof($response, $file, $this->dealership->id);

            expect($proof->original_filename)->toBe('my_original_photo.jpg');
        });

        it('stores correct mime type', function () {
            $task = Task::factory()->completionWithProof()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);
            $file = UploadedFile::fake()->image('photo.png', 100, 100);

            $proof = $this->proofService->storeProof($response, $file, $this->dealership->id);

            expect($proof->mime_type)->toBe('image/png');
        });

        it('stores correct file size', function () {
            $task = Task::factory()->completionWithProof()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);
            $response = TaskResponse::create([
                'task_id' => $task->id,
                'user_id' => $this->employee->id,
                'status' => 'pending_review',
                'responded_at' => Carbon::now(),
            ]);
            $file = UploadedFile::fake()->image('photo.jpg', 100, 100);

            $proof = $this->proofService->storeProof($response, $file, $this->dealership->id);

            expect($proof->file_size)->toBeInt();
            expect($proof->file_size)->toBeGreaterThan(0);
        });
    });

    describe('Service Methods', function () {
        it('returns correct max files per response', function () {
            expect($this->proofService->getMaxFilesPerResponse())->toBe(5);
        });

        it('returns allowed extensions', function () {
            $extensions = $this->proofService->getAllowedExtensions();

            expect($extensions)->toContain('jpg');
            expect($extensions)->toContain('png');
            expect($extensions)->toContain('pdf');
            expect($extensions)->toContain('mp4');
        });

        it('returns allowed mime types', function () {
            $mimeTypes = $this->proofService->getAllowedMimeTypes();

            expect($mimeTypes)->toContain('image/jpeg');
            expect($mimeTypes)->toContain('image/png');
            expect($mimeTypes)->toContain('application/pdf');
            expect($mimeTypes)->toContain('video/mp4');
        });
    });
});
