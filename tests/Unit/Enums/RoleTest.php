<?php

declare(strict_types=1);

use App\Enums\Role;

describe('Role Enum', function () {
    describe('cases', function () {
        it('has all expected roles', function () {
            $cases = Role::cases();

            expect($cases)->toHaveCount(4);
            expect(Role::OWNER->value)->toBe('owner');
            expect(Role::MANAGER->value)->toBe('manager');
            expect(Role::OBSERVER->value)->toBe('observer');
            expect(Role::EMPLOYEE->value)->toBe('employee');
        });
    });

    describe('label', function () {
        it('returns Russian labels for all roles', function () {
            expect(Role::OWNER->label())->toBe('Владелец');
            expect(Role::MANAGER->label())->toBe('Управляющий');
            expect(Role::OBSERVER->label())->toBe('Смотрящий');
            expect(Role::EMPLOYEE->label())->toBe('Сотрудник');
        });
    });

    describe('level', function () {
        it('returns correct access levels', function () {
            expect(Role::OWNER->level())->toBe(4);
            expect(Role::MANAGER->level())->toBe(3);
            expect(Role::OBSERVER->level())->toBe(2);
            expect(Role::EMPLOYEE->level())->toBe(1);
        });

        it('maintains correct hierarchy order', function () {
            expect(Role::OWNER->level())->toBeGreaterThan(Role::MANAGER->level());
            expect(Role::MANAGER->level())->toBeGreaterThan(Role::OBSERVER->level());
            expect(Role::OBSERVER->level())->toBeGreaterThan(Role::EMPLOYEE->level());
        });
    });

    describe('values', function () {
        it('returns all role values as array', function () {
            $values = Role::values();

            expect($values)->toBeArray();
            expect($values)->toContain('owner');
            expect($values)->toContain('manager');
            expect($values)->toContain('observer');
            expect($values)->toContain('employee');
        });
    });

    describe('tryFromString', function () {
        it('returns enum for valid string', function () {
            expect(Role::tryFromString('owner'))->toBe(Role::OWNER);
            expect(Role::tryFromString('manager'))->toBe(Role::MANAGER);
            expect(Role::tryFromString('observer'))->toBe(Role::OBSERVER);
            expect(Role::tryFromString('employee'))->toBe(Role::EMPLOYEE);
        });

        it('returns null for invalid string', function () {
            expect(Role::tryFromString('admin'))->toBeNull();
            expect(Role::tryFromString('superuser'))->toBeNull();
        });

        it('returns null for null input', function () {
            expect(Role::tryFromString(null))->toBeNull();
        });
    });
});
