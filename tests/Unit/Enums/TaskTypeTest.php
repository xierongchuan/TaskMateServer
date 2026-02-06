<?php

declare(strict_types=1);

use App\Enums\TaskType;

describe('TaskType Enum', function () {
    describe('cases', function () {
        it('has all expected task types', function () {
            $cases = TaskType::cases();

            expect($cases)->toHaveCount(2);
            expect(TaskType::INDIVIDUAL->value)->toBe('individual');
            expect(TaskType::GROUP->value)->toBe('group');
        });
    });

    describe('label', function () {
        it('returns Russian labels for all types', function () {
            expect(TaskType::INDIVIDUAL->label())->toBe('Индивидуальная');
            expect(TaskType::GROUP->label())->toBe('Групповая');
        });
    });

    describe('values', function () {
        it('returns all type values as array', function () {
            $values = TaskType::values();

            expect($values)->toBeArray();
            expect($values)->toHaveCount(2);
            expect($values)->toContain('individual');
            expect($values)->toContain('group');
        });
    });

    describe('tryFromString', function () {
        it('returns enum for valid string', function () {
            expect(TaskType::tryFromString('individual'))->toBe(TaskType::INDIVIDUAL);
            expect(TaskType::tryFromString('group'))->toBe(TaskType::GROUP);
        });

        it('returns null for invalid string', function () {
            expect(TaskType::tryFromString('team'))->toBeNull();
        });

        it('returns null for null input', function () {
            expect(TaskType::tryFromString(null))->toBeNull();
        });
    });
});
