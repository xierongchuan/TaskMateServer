<?php

declare(strict_types=1);

use App\Enums\Recurrence;

describe('Recurrence Enum', function () {
    describe('cases', function () {
        it('has all expected recurrence types', function () {
            $cases = Recurrence::cases();

            expect($cases)->toHaveCount(4);
            expect(Recurrence::NONE->value)->toBe('none');
            expect(Recurrence::DAILY->value)->toBe('daily');
            expect(Recurrence::WEEKLY->value)->toBe('weekly');
            expect(Recurrence::MONTHLY->value)->toBe('monthly');
        });
    });

    describe('label', function () {
        it('returns Russian labels for all types', function () {
            expect(Recurrence::NONE->label())->toBe('Без повторения');
            expect(Recurrence::DAILY->label())->toBe('Ежедневно');
            expect(Recurrence::WEEKLY->label())->toBe('Еженедельно');
            expect(Recurrence::MONTHLY->label())->toBe('Ежемесячно');
        });
    });

    describe('values', function () {
        it('returns all recurrence values as array', function () {
            $values = Recurrence::values();

            expect($values)->toBeArray();
            expect($values)->toHaveCount(4);
            expect($values)->toContain('none');
            expect($values)->toContain('daily');
            expect($values)->toContain('weekly');
            expect($values)->toContain('monthly');
        });
    });

    describe('tryFromString', function () {
        it('returns enum for valid string', function () {
            expect(Recurrence::tryFromString('daily'))->toBe(Recurrence::DAILY);
            expect(Recurrence::tryFromString('none'))->toBe(Recurrence::NONE);
        });

        it('returns null for invalid string', function () {
            expect(Recurrence::tryFromString('yearly'))->toBeNull();
        });

        it('returns null for null input', function () {
            expect(Recurrence::tryFromString(null))->toBeNull();
        });
    });

    describe('isRecurring', function () {
        it('returns false for NONE', function () {
            expect(Recurrence::NONE->isRecurring())->toBeFalse();
        });

        it('returns true for all recurring types', function () {
            expect(Recurrence::DAILY->isRecurring())->toBeTrue();
            expect(Recurrence::WEEKLY->isRecurring())->toBeTrue();
            expect(Recurrence::MONTHLY->isRecurring())->toBeTrue();
        });
    });
});
