<?php

declare(strict_types=1);

use App\Enums\ExpenseStatus;

describe('ExpenseStatus Enum', function () {
    describe('cases', function () {
        it('has all expected statuses', function () {
            $cases = ExpenseStatus::cases();

            expect($cases)->toHaveCount(5);
            expect(ExpenseStatus::PENDING->value)->toBe('pending');
            expect(ExpenseStatus::APPROVED->value)->toBe('approved');
            expect(ExpenseStatus::DECLINED->value)->toBe('declined');
            expect(ExpenseStatus::ISSUED->value)->toBe('issued');
            expect(ExpenseStatus::CANCELLED->value)->toBe('cancelled');
        });
    });

    describe('label', function () {
        it('returns Russian labels for all statuses', function () {
            expect(ExpenseStatus::PENDING->label())->toBe('Ожидает руководителя');
            expect(ExpenseStatus::APPROVED->label())->toBe('Одобрено руководителем');
            expect(ExpenseStatus::DECLINED->label())->toBe('Отклонено руководителем');
            expect(ExpenseStatus::ISSUED->label())->toBe('Выдано (бухгалтер)');
            expect(ExpenseStatus::CANCELLED->label())->toBe('Отменено');
        });
    });

    describe('values', function () {
        it('returns all status values as array', function () {
            $values = ExpenseStatus::values();

            expect($values)->toBeArray();
            expect($values)->toHaveCount(5);
            expect($values)->toContain('pending');
            expect($values)->toContain('approved');
            expect($values)->toContain('declined');
            expect($values)->toContain('issued');
            expect($values)->toContain('cancelled');
        });
    });

    describe('tryFromString', function () {
        it('returns enum for valid string', function () {
            expect(ExpenseStatus::tryFromString('pending'))->toBe(ExpenseStatus::PENDING);
            expect(ExpenseStatus::tryFromString('approved'))->toBe(ExpenseStatus::APPROVED);
            expect(ExpenseStatus::tryFromString('cancelled'))->toBe(ExpenseStatus::CANCELLED);
        });

        it('returns null for invalid string', function () {
            expect(ExpenseStatus::tryFromString('rejected'))->toBeNull();
        });

        it('returns null for null input', function () {
            expect(ExpenseStatus::tryFromString(null))->toBeNull();
        });
    });
});
