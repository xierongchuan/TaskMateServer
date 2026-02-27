<?php

declare(strict_types=1);

use App\Enums\DateRange;

describe('DateRange Enum', function () {
    describe('cases', function () {
        it('has all expected date ranges', function () {
            $cases = DateRange::cases();

            expect($cases)->toHaveCount(4);
            expect(DateRange::ALL->value)->toBe('all');
            expect(DateRange::TODAY->value)->toBe('today');
            expect(DateRange::WEEK->value)->toBe('week');
            expect(DateRange::MONTH->value)->toBe('month');
        });
    });

    describe('label', function () {
        it('returns Russian labels for all ranges', function () {
            expect(DateRange::ALL->label())->toBe('Все');
            expect(DateRange::TODAY->label())->toBe('Сегодня');
            expect(DateRange::WEEK->label())->toBe('Неделя');
            expect(DateRange::MONTH->label())->toBe('Месяц');
        });
    });

    describe('values', function () {
        it('returns all range values as array', function () {
            $values = DateRange::values();

            expect($values)->toBeArray();
            expect($values)->toHaveCount(4);
            expect($values)->toContain('all');
            expect($values)->toContain('today');
            expect($values)->toContain('week');
            expect($values)->toContain('month');
        });
    });

    describe('tryFromString', function () {
        it('returns enum for valid string', function () {
            expect(DateRange::tryFromString('today'))->toBe(DateRange::TODAY);
            expect(DateRange::tryFromString('all'))->toBe(DateRange::ALL);
        });

        it('returns null for invalid string', function () {
            expect(DateRange::tryFromString('year'))->toBeNull();
        });

        it('returns null for null input', function () {
            expect(DateRange::tryFromString(null))->toBeNull();
        });
    });
});
