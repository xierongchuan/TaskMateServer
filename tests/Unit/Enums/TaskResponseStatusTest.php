<?php

declare(strict_types=1);

use App\Enums\TaskResponseStatus;

describe('TaskResponseStatus Enum', function () {
    describe('cases', function () {
        it('has all expected statuses', function () {
            $cases = TaskResponseStatus::cases();

            expect($cases)->toHaveCount(5);
            expect(TaskResponseStatus::PENDING->value)->toBe('pending');
            expect(TaskResponseStatus::ACKNOWLEDGED->value)->toBe('acknowledged');
            expect(TaskResponseStatus::PENDING_REVIEW->value)->toBe('pending_review');
            expect(TaskResponseStatus::COMPLETED->value)->toBe('completed');
            expect(TaskResponseStatus::REJECTED->value)->toBe('rejected');
        });
    });

    describe('label', function () {
        it('returns Russian labels for all statuses', function () {
            expect(TaskResponseStatus::PENDING->label())->toBe('Ожидает');
            expect(TaskResponseStatus::ACKNOWLEDGED->label())->toBe('Принята');
            expect(TaskResponseStatus::PENDING_REVIEW->label())->toBe('На проверке');
            expect(TaskResponseStatus::COMPLETED->label())->toBe('Выполнена');
            expect(TaskResponseStatus::REJECTED->label())->toBe('Отклонена');
        });
    });

    describe('values', function () {
        it('returns all status values as array', function () {
            $values = TaskResponseStatus::values();

            expect($values)->toBeArray();
            expect($values)->toContain('pending');
            expect($values)->toContain('acknowledged');
            expect($values)->toContain('pending_review');
            expect($values)->toContain('completed');
            expect($values)->toContain('rejected');
        });
    });

    describe('tryFromString', function () {
        it('returns enum for valid string', function () {
            $status = TaskResponseStatus::tryFromString('completed');

            expect($status)->toBe(TaskResponseStatus::COMPLETED);
        });

        it('returns null for invalid string', function () {
            $status = TaskResponseStatus::tryFromString('invalid');

            expect($status)->toBeNull();
        });

        it('returns null for null input', function () {
            $status = TaskResponseStatus::tryFromString(null);

            expect($status)->toBeNull();
        });
    });

    describe('allowedForUpdateStatus', function () {
        it('returns statuses allowed in updateStatus API', function () {
            $allowed = TaskResponseStatus::allowedForUpdateStatus();

            expect($allowed)->toBeArray();
            expect($allowed)->toContain('pending');
            expect($allowed)->toContain('acknowledged');
            expect($allowed)->toContain('pending_review');
            expect($allowed)->toContain('completed');
            // rejected is NOT allowed via updateStatus API (only through verification)
            expect($allowed)->not->toContain('rejected');
        });
    });

    describe('isFinal', function () {
        it('returns true only for completed status', function () {
            expect(TaskResponseStatus::COMPLETED->isFinal())->toBeTrue();
            expect(TaskResponseStatus::PENDING->isFinal())->toBeFalse();
            expect(TaskResponseStatus::ACKNOWLEDGED->isFinal())->toBeFalse();
            expect(TaskResponseStatus::PENDING_REVIEW->isFinal())->toBeFalse();
            expect(TaskResponseStatus::REJECTED->isFinal())->toBeFalse();
        });
    });

    describe('requiresVerification', function () {
        it('returns only pending_review status', function () {
            $statuses = TaskResponseStatus::requiresVerification();

            expect($statuses)->toBeArray();
            expect($statuses)->toContain('pending_review');
            expect($statuses)->toHaveCount(1);
        });
    });
});
