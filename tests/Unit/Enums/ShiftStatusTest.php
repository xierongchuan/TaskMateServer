<?php

declare(strict_types=1);

use App\Enums\ShiftStatus;

describe('ShiftStatus Enum', function () {
    describe('cases', function () {
        it('has all expected statuses', function () {
            $cases = ShiftStatus::cases();

            expect($cases)->toHaveCount(4);
            expect(ShiftStatus::OPEN->value)->toBe('open');
            expect(ShiftStatus::LATE->value)->toBe('late');
            expect(ShiftStatus::CLOSED->value)->toBe('closed');
            expect(ShiftStatus::REPLACED->value)->toBe('replaced');
        });
    });

    describe('label', function () {
        it('returns Russian labels for all statuses', function () {
            expect(ShiftStatus::OPEN->label())->toBe('Открыта');
            expect(ShiftStatus::LATE->label())->toBe('Открыта (опоздание)');
            expect(ShiftStatus::CLOSED->label())->toBe('Закрыта');
            expect(ShiftStatus::REPLACED->label())->toBe('Замещена');
        });
    });

    describe('activeStatuses', function () {
        it('returns open and late as active', function () {
            $active = ShiftStatus::activeStatuses();

            expect($active)->toHaveCount(2);
            expect($active)->toContain(ShiftStatus::OPEN);
            expect($active)->toContain(ShiftStatus::LATE);
            expect($active)->not->toContain(ShiftStatus::CLOSED);
            expect($active)->not->toContain(ShiftStatus::REPLACED);
        });
    });

    describe('activeStatusValues', function () {
        it('returns active status string values', function () {
            $values = ShiftStatus::activeStatusValues();

            expect($values)->toBeArray();
            expect($values)->toHaveCount(2);
            expect($values)->toContain('open');
            expect($values)->toContain('late');
        });
    });

    describe('closedStatuses', function () {
        it('returns closed and replaced', function () {
            $closed = ShiftStatus::closedStatuses();

            expect($closed)->toHaveCount(2);
            expect($closed)->toContain(ShiftStatus::CLOSED);
            expect($closed)->toContain(ShiftStatus::REPLACED);
            expect($closed)->not->toContain(ShiftStatus::OPEN);
            expect($closed)->not->toContain(ShiftStatus::LATE);
        });
    });

    describe('values', function () {
        it('returns all status values as array', function () {
            $values = ShiftStatus::values();

            expect($values)->toBeArray();
            expect($values)->toHaveCount(4);
            expect($values)->toContain('open');
            expect($values)->toContain('late');
            expect($values)->toContain('closed');
            expect($values)->toContain('replaced');
        });
    });

    describe('tryFromString', function () {
        it('returns enum for valid string', function () {
            expect(ShiftStatus::tryFromString('open'))->toBe(ShiftStatus::OPEN);
            expect(ShiftStatus::tryFromString('closed'))->toBe(ShiftStatus::CLOSED);
        });

        it('returns null for invalid string', function () {
            expect(ShiftStatus::tryFromString('invalid'))->toBeNull();
        });

        it('returns null for null input', function () {
            expect(ShiftStatus::tryFromString(null))->toBeNull();
        });
    });
});
