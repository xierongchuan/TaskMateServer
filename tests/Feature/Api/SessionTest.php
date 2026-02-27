<?php

declare(strict_types=1);

use App\Models\User;
use App\Enums\Role;
use Illuminate\Support\Facades\Hash;

describe('Session API', function () {
    describe('POST /api/v1/session (Login)', function () {
        it('logs in user with valid credentials', function () {
            // Arrange
            $password = 'password123';
            $user = User::factory()->create([
                'login' => 'testuser',
                'password' => Hash::make($password),
                'role' => Role::EMPLOYEE->value,
            ]);

            // Act
            $response = $this->postJson('/api/v1/session', [
                'login' => 'testuser',
                'password' => $password,
            ]);

            // Assert
            $response->assertStatus(200)
                ->assertJsonStructure([
                    'token',
                    'user' => ['id', 'login', 'full_name', 'role', 'dealership_id', 'phone'],
                ]);

            expect($response->json('user.login'))->toBe('testuser')
                ->and($response->json('token'))->toBeString();
        });

        it('fails with invalid credentials', function () {
            // Arrange
            User::factory()->create([
                'login' => 'testuser',
                'password' => Hash::make('password123'),
            ]);

            // Act
            $response = $this->postJson('/api/v1/session', [
                'login' => 'testuser',
                'password' => 'wrongpassword',
            ]);

            // Assert
            expect($response->status())->toBe(401);
        });

        it('fails with non-existent user', function () {
            // Act
            $response = $this->postJson('/api/v1/session', [
                'login' => 'nonexistent',
                'password' => 'password123',
            ]);

            // Assert
            expect($response->status())->toBe(401);
        });

        it('validates required fields', function () {
            // Act
            $response = $this->postJson('/api/v1/session', [
                'login' => '',
                'password' => '',
            ]);

            // Assert
            expect($response->status())->toBe(422);
        });
    });

    describe('DELETE /api/v1/session (Logout)', function () {
        it('fails without authentication', function () {
            // Act
            $response = $this->deleteJson('/api/v1/session');

            // Assert
            expect($response->status())->toBe(401);
        });
    });

    describe('GET /api/v1/session/current (Current User)', function () {
        it('returns current authenticated user', function () {
            // Arrange
            $user = User::factory()->create([
                'login' => 'currentuser',
                'full_name' => 'Current User',
            ]);

            // Act
            $response = $this->actingAs($user, 'sanctum')
                ->getJson('/api/v1/session/current');

            // Assert
            $response->assertStatus(200)
                ->assertJsonStructure([
                    'user' => ['id', 'login', 'full_name', 'role'],
                ]);

            expect($response->json('user.login'))->toBe('currentuser')
                ->and($response->json('user.full_name'))->toBe('Current User');
        });

        it('fails without authentication', function () {
            // Act
            $response = $this->getJson('/api/v1/session/current');

            // Assert
            expect($response->status())->toBe(401);
        });
    });
});
