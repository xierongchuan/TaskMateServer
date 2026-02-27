<?php

declare(strict_types=1);

use App\Enums\TaskStatus;

describe('TaskStatus Enum', function () {
    describe('cases', function () {
        it('has all expected statuses', function () {
            $cases = TaskStatus::cases();

            expect($cases)->toHaveCount(6);
            expect(TaskStatus::PENDING->value)->toBe('pending');
            expect(TaskStatus::ACKNOWLEDGED->value)->toBe('acknowledged');
            expect(TaskStatus::PENDING_REVIEW->value)->toBe('pending_review');
            expect(TaskStatus::COMPLETED->value)->toBe('completed');
            expect(TaskStatus::COMPLETED_LATE->value)->toBe('completed_late');
            expect(TaskStatus::OVERDUE->value)->toBe('overdue');
        });
    });

    describe('label', function () {
        it('returns Russian labels for all statuses', function () {
            expect(TaskStatus::PENDING->label())->toBe('Ожидает');
            expect(TaskStatus::ACKNOWLEDGED->label())->toBe('Принята');
            expect(TaskStatus::PENDING_REVIEW->label())->toBe('На проверке');
            expect(TaskStatus::COMPLETED->label())->toBe('Выполнена');
            expect(TaskStatus::COMPLETED_LATE->label())->toBe('Выполнена с опозданием');
            expect(TaskStatus::OVERDUE->label())->toBe('Просрочена');
        });
    });

    describe('values', function () {
        it('returns all status values as array', function () {
            $values = TaskStatus::values();

            expect($values)->toBeArray();
            expect($values)->toHaveCount(6);
            expect($values)->toContain('pending');
            expect($values)->toContain('acknowledged');
            expect($values)->toContain('pending_review');
            expect($values)->toContain('completed');
            expect($values)->toContain('completed_late');
            expect($values)->toContain('overdue');
        });
    });

    describe('tryFromString', function () {
        it('returns enum for valid string', function () {
            expect(TaskStatus::tryFromString('pending'))->toBe(TaskStatus::PENDING);
            expect(TaskStatus::tryFromString('completed'))->toBe(TaskStatus::COMPLETED);
            expect(TaskStatus::tryFromString('overdue'))->toBe(TaskStatus::OVERDUE);
        });

        it('returns null for invalid string', function () {
            expect(TaskStatus::tryFromString('invalid'))->toBeNull();
        });

        it('returns null for null input', function () {
            expect(TaskStatus::tryFromString(null))->toBeNull();
        });
    });

    describe('completedStatuses', function () {
        it('returns only completed and completed_late', function () {
            $statuses = TaskStatus::completedStatuses();

            expect($statuses)->toBeArray();
            expect($statuses)->toHaveCount(2);
            expect($statuses)->toContain('completed');
            expect($statuses)->toContain('completed_late');
            expect($statuses)->not->toContain('pending');
            expect($statuses)->not->toContain('overdue');
        });
    });
});
