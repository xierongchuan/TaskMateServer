<?php

declare(strict_types=1);

use App\Enums\Priority;

describe('Priority Enum', function () {
    describe('cases', function () {
        it('has all expected priorities', function () {
            $cases = Priority::cases();

            expect($cases)->toHaveCount(3);
            expect(Priority::LOW->value)->toBe('low');
            expect(Priority::MEDIUM->value)->toBe('medium');
            expect(Priority::HIGH->value)->toBe('high');
        });
    });

    describe('label', function () {
        it('returns Russian labels for all priorities', function () {
            expect(Priority::LOW->label())->toBe('Низкий');
            expect(Priority::MEDIUM->label())->toBe('Средний');
            expect(Priority::HIGH->label())->toBe('Высокий');
        });
    });

    describe('level', function () {
        it('returns correct priority levels', function () {
            expect(Priority::LOW->level())->toBe(1);
            expect(Priority::MEDIUM->level())->toBe(2);
            expect(Priority::HIGH->level())->toBe(3);
        });

        it('maintains correct order', function () {
            expect(Priority::HIGH->level())->toBeGreaterThan(Priority::MEDIUM->level());
            expect(Priority::MEDIUM->level())->toBeGreaterThan(Priority::LOW->level());
        });
    });

    describe('values', function () {
        it('returns all priority values as array', function () {
            $values = Priority::values();

            expect($values)->toBeArray();
            expect($values)->toHaveCount(3);
            expect($values)->toContain('low');
            expect($values)->toContain('medium');
            expect($values)->toContain('high');
        });
    });

    describe('tryFromString', function () {
        it('returns enum for valid string', function () {
            expect(Priority::tryFromString('low'))->toBe(Priority::LOW);
            expect(Priority::tryFromString('medium'))->toBe(Priority::MEDIUM);
            expect(Priority::tryFromString('high'))->toBe(Priority::HIGH);
        });

        it('returns null for invalid string', function () {
            expect(Priority::tryFromString('urgent'))->toBeNull();
        });

        it('returns null for null input', function () {
            expect(Priority::tryFromString(null))->toBeNull();
        });
    });

    describe('default', function () {
        it('returns MEDIUM as default', function () {
            expect(Priority::default())->toBe(Priority::MEDIUM);
        });
    });
});
