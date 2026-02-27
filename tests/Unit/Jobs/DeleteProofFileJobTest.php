<?php

declare(strict_types=1);

use App\Jobs\DeleteProofFileJob;
use Illuminate\Support\Facades\Storage;

describe('DeleteProofFileJob', function () {
    beforeEach(function () {
        Storage::fake('task_proofs');
    });

    it('deletes existing file', function () {
        // Arrange
        Storage::disk('task_proofs')->put('test_file.jpg', 'fake content');
        expect(Storage::disk('task_proofs')->exists('test_file.jpg'))->toBeTrue();

        // Act
        $job = new DeleteProofFileJob('test_file.jpg', 'task_proofs');
        $job->handle();

        // Assert
        expect(Storage::disk('task_proofs')->exists('test_file.jpg'))->toBeFalse();
    });

    it('handles non-existent file gracefully', function () {
        // Act
        $job = new DeleteProofFileJob('non_existent.jpg', 'task_proofs');
        $job->handle();

        // Assert - should not throw exception
        expect(true)->toBeTrue();
    });

    it('uses correct queue', function () {
        // Act
        $job = new DeleteProofFileJob('test.jpg', 'task_proofs');

        // Assert
        expect($job->queue)->toBe('file_cleanup');
    });

    it('has retry configuration', function () {
        // Act
        $job = new DeleteProofFileJob('test.jpg', 'task_proofs');

        // Assert
        expect($job->tries)->toBe(3);
        expect($job->backoff)->toBe(60);
    });

    it('works with different disk', function () {
        // Arrange
        Storage::fake('local');
        Storage::disk('local')->put('shared/test.jpg', 'fake content');
        expect(Storage::disk('local')->exists('shared/test.jpg'))->toBeTrue();

        // Act
        $job = new DeleteProofFileJob('shared/test.jpg', 'local');
        $job->handle();

        // Assert
        expect(Storage::disk('local')->exists('shared/test.jpg'))->toBeFalse();
    });
});
