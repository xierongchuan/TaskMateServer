<?php

declare(strict_types=1);

use App\Models\User;
use App\Enums\Role;
use Illuminate\Support\Facades\Hash;

describe('User Model', function () {
    it('can create a user with all fields', function () {
        // Arrange & Act
        $user = User::create([
            'login' => 'testuser123',
            'full_name' => 'Test User Full Name',

            'phone' => '+998901234567',
            'role' => Role::EMPLOYEE->value,
            'password' => Hash::make('password123'),
        ]);

        // Assert
        expect($user)
            ->toBeInstanceOf(User::class)
            ->and($user->login)->toBe('testuser123')
            ->and($user->full_name)->toBe('Test User Full Name')

            ->and($user->phone)->toBe('+998901234567')
            ->and($user->role)->toBe(Role::EMPLOYEE)
            ->and($user->exists)->toBeTrue();
    });

    it('can create user with minimum required fields', function () {
        // Arrange & Act
        $user = User::create([
            'login' => 'minuser',
            'role' => Role::EMPLOYEE->value,
        ]);

        // Assert
        expect($user)
            ->toBeInstanceOf(User::class)
            ->and($user->login)->toBe('minuser')
            ->and($user->role)->toBe(Role::EMPLOYEE)
            ->and($user->exists)->toBeTrue();
    });

    it('hides password in array representation', function () {
        // Arrange
        $user = User::factory()->create([
            'password' => Hash::make('secret123'),
        ]);

        // Act
        $userArray = $user->toArray();

        // Assert
        expect($userArray)->not->toHaveKey('password');
    });

    it('can create users with different roles', function () {
        // Arrange & Act
        $owner = User::create([
            'login' => 'owner1',
            'role' => Role::OWNER->value,
        ]);

        $manager = User::create([
            'login' => 'manager1',
            'role' => Role::MANAGER->value,
        ]);

        $employee = User::create([
            'login' => 'employee1',
            'role' => Role::EMPLOYEE->value,
        ]);

        // Assert
        expect($owner->role)->toBe(Role::OWNER)
            ->and($manager->role)->toBe(Role::MANAGER)
            ->and($employee->role)->toBe(Role::EMPLOYEE);
    });

    it('can query users by role', function () {
        // Arrange
        User::factory()->create([
            'role' => Role::MANAGER->value,
        ]);

        User::factory()->create([
            'role' => Role::MANAGER->value,
        ]);

        User::factory()->create([
            'role' => Role::EMPLOYEE->value,
        ]);

        // Act
        $managers = User::where('role', Role::MANAGER->value)->get();

        // Assert
        expect($managers)->toHaveCount(2)
            ->and($managers->first()->role)->toBe(Role::MANAGER);
    });



    it('stores and retrieves phone numbers correctly', function () {
        // Arrange
        $phoneNumbers = [
            '+998901234567',
            '+1234567890',
            '+7123456789',
        ];

        // Act & Assert
        foreach ($phoneNumbers as $phone) {
            $user = User::factory()->create(['phone' => $phone]);
            expect($user->phone)->toBe($phone);
        }
    });

    it('can update user fields', function () {
        // Arrange
        $user = User::factory()->create([
            'full_name' => 'Original Name',
            'phone' => '+998901111111',
        ]);

        // Act
        $user->update([
            'full_name' => 'Updated Name',
            'phone' => '+998902222222',
        ]);

        // Assert
        expect($user->fresh())
            ->full_name->toBe('Updated Name')
            ->phone->toBe('+998902222222');
    });
});
