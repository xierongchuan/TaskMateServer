<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\AutoDealership;
use App\Models\Shift;
use App\Models\TaskGenerator;
use App\Models\TaskGeneratorAssignment;
use App\Models\User;

describe('Users API', function () {
    describe('GET /api/v1/users', function () {
        it('returns paginated list of users', function () {
            // Arrange
            $manager = User::factory()->create(['role' => Role::MANAGER->value]);
            User::factory(5)->create(['role' => Role::EMPLOYEE->value]);

            // Act
            $response = $this->actingAs($manager, 'sanctum')
                ->getJson('/api/v1/users');

            // Assert
            $response->assertStatus(200);
            expect($response->json('data'))->toBeArray();
        });

        it('filters users by role', function () {
            // Arrange
            $manager = User::factory()->create(['role' => Role::MANAGER->value]);
            User::factory(3)->create(['role' => Role::MANAGER->value]);
            User::factory(2)->create(['role' => Role::EMPLOYEE->value]);

            // Act
            $response = $this->actingAs($manager, 'sanctum')
                ->getJson('/api/v1/users?role='.Role::MANAGER->value);

            // Assert
            $response->assertStatus(200);
        });

        it('requires authentication', function () {
            // Act
            $response = $this->getJson('/api/v1/users');

            // Assert
            expect($response->status())->toBe(401);
        });
    });

    describe('GET /api/v1/users/{id}', function () {
        it('returns single user with relations', function () {
            // Arrange
            $user = User::factory()->create();
            $manager = User::factory()->create(['role' => Role::MANAGER->value]);

            // Act
            $response = $this->actingAs($manager, 'sanctum')
                ->getJson("/api/v1/users/{$user->id}");

            // Assert
            $response->assertStatus(200);
            expect($response->json())->toBeArray();
        });

        it('returns primary dealership when user has dealership_id', function () {
            // Arrange
            $dealership = AutoDealership::factory()->create([
                'name' => 'Автосалон Центр',
            ]);
            $user = User::factory()->create([
                'dealership_id' => $dealership->id,
            ]);
            $manager = User::factory()->create([
                'role' => Role::MANAGER->value,
                'dealership_id' => $dealership->id,
            ]);

            // Act
            $response = $this->actingAs($manager, 'sanctum')
                ->getJson("/api/v1/users/{$user->id}");

            // Assert
            $response->assertStatus(200)
                ->assertJsonPath('data.dealership.id', $dealership->id)
                ->assertJsonPath('data.dealership.name', 'Автосалон Центр');
        });

        it('returns 404 for non-existent user', function () {
            // Arrange
            $manager = User::factory()->create(['role' => Role::MANAGER->value]);

            // Act
            $response = $this->actingAs($manager, 'sanctum')
                ->getJson('/api/v1/users/99999');

            // Assert
            expect($response->status())->toBe(404);
        });
    });

    describe('POST /api/v1/users (Create User)', function () {
        it('creates new user with valid data', function () {
            // Arrange
            $manager = User::factory()->create(['role' => Role::MANAGER->value]);

            // Act
            $response = $this->actingAs($manager, 'sanctum')
                ->postJson('/api/v1/users', [
                    'login' => 'newuser',
                    'password' => 'SecurePassword123!',
                    'password_confirmation' => 'SecurePassword123!',
                    'full_name' => 'New User',
                    'role' => Role::EMPLOYEE->value,
                    'phone' => '+998901234567',
                ]);

            // Assert
            $response->assertStatus(201);
            $this->assertDatabaseHas('users', ['login' => 'newuser']);
        });

        it('validates required fields', function () {
            // Arrange
            $manager = User::factory()->create(['role' => Role::MANAGER->value]);

            // Act
            $response = $this->actingAs($manager, 'sanctum')
                ->postJson('/api/v1/users', [
                    'login' => '',
                    'password' => '',
                ]);

            // Assert
            expect($response->status())->toBe(422);
        });

        it('validates login format (latin letters, digits, max one dot)', function () {
            $manager = User::factory()->create(['role' => Role::MANAGER->value]);

            // Case 1: Too long (>64)
            $response = $this->actingAs($manager, 'sanctum')
                ->postJson('/api/v1/users', [
                    'login' => str_repeat('a', 65),
                    'password' => 'SecurePassword123!',
                    'full_name' => 'User',
                    'role' => Role::EMPLOYEE->value,
                    'phone' => '+1234567890',
                ]);
            expect($response->status())->toBe(422);

            // Case 2: Invalid chars (Russian)
            $response = $this->actingAs($manager, 'sanctum')
                ->postJson('/api/v1/users', [
                    'login' => 'логин',
                    'password' => 'SecurePassword123!',
                    'full_name' => 'User',
                    'role' => Role::EMPLOYEE->value,
                    'phone' => '+1234567890',
                ]);
            expect($response->status())->toBe(422);

            // Case 3: Invalid Special chars (e.g. @)
            $response = $this->actingAs($manager, 'sanctum')
                ->postJson('/api/v1/users', [
                    'login' => 'user@name',
                    'password' => 'SecurePassword123!',
                    'full_name' => 'User',
                    'role' => Role::EMPLOYEE->value,
                    'phone' => '+1234567890',
                ]);
            expect($response->status())->toBe(422);

            // Case 4: Multiple dots
            $response = $this->actingAs($manager, 'sanctum')
                ->postJson('/api/v1/users', [
                    'login' => 'user.name.test',
                    'password' => 'SecurePassword123!',
                    'full_name' => 'User',
                    'role' => Role::EMPLOYEE->value,
                    'phone' => '+1234567890',
                ]);
            expect($response->status())->toBe(422);

            // Case 5: Multiple underscores
            $response = $this->actingAs($manager, 'sanctum')
                ->postJson('/api/v1/users', [
                    'login' => 'user_name_test',
                    'password' => 'SecurePassword123!',
                    'full_name' => 'User',
                    'role' => Role::EMPLOYEE->value,
                    'phone' => '+1234567890',
                ]);
            expect($response->status())->toBe(422);

            // Case 6: Valid dot
            $response = $this->actingAs($manager, 'sanctum')
                ->postJson('/api/v1/users', [
                    'login' => 'user.name',
                    'password' => 'SecurePassword123!',
                    'full_name' => 'User',
                    'role' => Role::EMPLOYEE->value,
                    'phone' => '+1234567890',
                ]);
            expect($response->status())->toBe(201);

            // Case 7: Valid underscore
            $response = $this->actingAs($manager, 'sanctum')
                ->postJson('/api/v1/users', [
                    'login' => 'user_name',
                    'password' => 'SecurePassword123!',
                    'full_name' => 'User',
                    'role' => Role::EMPLOYEE->value,
                    'phone' => '+1234567890',
                ]);
            expect($response->status())->toBe(201);

            // Case 8: Valid dot AND underscore
            $response = $this->actingAs($manager, 'sanctum')
                ->postJson('/api/v1/users', [
                    'login' => 'user.name_test',
                    'password' => 'SecurePassword123!',
                    'full_name' => 'User',
                    'role' => Role::EMPLOYEE->value,
                    'phone' => '+1234567890',
                ]);
            expect($response->status())->toBe(201);
        });

        it('requires manager or owner role', function () {
            // Arrange
            $employee = User::factory()->create(['role' => Role::EMPLOYEE->value]);

            // Act
            $response = $this->actingAs($employee, 'sanctum')
                ->postJson('/api/v1/users', [
                    'login' => 'newuser',
                    'password' => 'Password123!',
                    'password_confirmation' => 'Password123!',
                    'role' => Role::EMPLOYEE->value,
                ]);

            // Assert
            expect($response->status())->toBe(403);
        });
    });

    describe('PUT /api/v1/users/{id} (Update User)', function () {
        it('updates user with valid data', function () {
            // Arrange
            $user = User::factory()->create(['full_name' => 'Old Name', 'role' => Role::EMPLOYEE->value]);
            $manager = User::factory()->create(['role' => Role::MANAGER->value]);

            // Act
            $response = $this->actingAs($manager, 'sanctum')
                ->putJson("/api/v1/users/{$user->id}", [
                    'full_name' => 'Updated Name',
                    'phone' => '+998901234567',
                ]);

            // Assert
            $response->assertStatus(200);
            $this->assertDatabaseHas('users', [
                'id' => $user->id,
                'full_name' => 'Updated Name',
            ]);
        });

        it('requires manager or owner role for update', function () {
            // Arrange
            $user = User::factory()->create();
            $employee = User::factory()->create(['role' => Role::EMPLOYEE->value]);

            // Act
            $response = $this->actingAs($employee, 'sanctum')
                ->putJson("/api/v1/users/{$user->id}", [
                    'full_name' => 'Updated Name',
                ]);

            // Assert
            expect($response->status())->toBe(403);
        });
    });

    describe('DELETE /api/v1/users/{id} (Delete User)', function () {
        it('deletes user successfully', function () {
            // Arrange
            $user = User::factory()->create(['role' => Role::EMPLOYEE->value]);
            $manager = User::factory()->create(['role' => Role::MANAGER->value]);

            // Act
            $response = $this->actingAs($manager, 'sanctum')
                ->deleteJson("/api/v1/users/{$user->id}");

            // Assert
            expect($response->status())->toBe(200);
        });

        it('soft deletes user with shift history and preserves closed shifts', function () {
            $dealership = AutoDealership::factory()->create();
            $user = User::factory()->create([
                'role' => Role::EMPLOYEE->value,
                'dealership_id' => $dealership->id,
            ]);
            $manager = User::factory()->create([
                'role' => Role::MANAGER->value,
                'dealership_id' => $dealership->id,
            ]);

            $shift = Shift::factory()->closed()->create([
                'user_id' => $user->id,
                'dealership_id' => $dealership->id,
            ]);

            $response = $this->actingAs($manager, 'sanctum')
                ->deleteJson("/api/v1/users/{$user->id}");

            $response->assertStatus(200);
            $this->assertSoftDeleted('users', ['id' => $user->id]);
            $this->assertDatabaseHas('shifts', [
                'id' => $shift->id,
                'user_id' => $user->id,
                'status' => 'closed',
            ]);

            $shiftWithUser = Shift::with('user')->findOrFail($shift->id);
            expect($shiftWithUser->user)->not->toBeNull()
                ->and($shiftWithUser->user->id)->toBe($user->id);
        });

        it('closes active shifts when soft deleting user', function () {
            $dealership = AutoDealership::factory()->create();
            $user = User::factory()->create([
                'role' => Role::EMPLOYEE->value,
                'dealership_id' => $dealership->id,
            ]);
            $manager = User::factory()->create([
                'role' => Role::MANAGER->value,
                'dealership_id' => $dealership->id,
            ]);

            $shift = Shift::factory()->late()->create([
                'user_id' => $user->id,
                'dealership_id' => $dealership->id,
                'shift_end' => null,
            ]);

            $response = $this->actingAs($manager, 'sanctum')
                ->deleteJson("/api/v1/users/{$user->id}");

            $response->assertStatus(200);
            $this->assertSoftDeleted('users', ['id' => $user->id]);

            $shift->refresh();
            expect($shift->status)->toBe('closed')
                ->and($shift->shift_end)->not->toBeNull();
        });

        it('removes user from task generators when soft deleting user', function () {
            $dealership = AutoDealership::factory()->create();
            $user = User::factory()->create([
                'role' => Role::EMPLOYEE->value,
                'dealership_id' => $dealership->id,
            ]);
            $manager = User::factory()->create([
                'role' => Role::MANAGER->value,
                'dealership_id' => $dealership->id,
            ]);
            $generator = TaskGenerator::factory()->create(['dealership_id' => $dealership->id]);
            $generator->assignments()->delete();
            TaskGeneratorAssignment::create([
                'generator_id' => $generator->id,
                'user_id' => $user->id,
            ]);

            $response = $this->actingAs($manager, 'sanctum')
                ->deleteJson("/api/v1/users/{$user->id}");

            $response->assertStatus(200);
            $this->assertSoftDeleted('users', ['id' => $user->id]);
            $this->assertDatabaseMissing('task_generator_assignments', [
                'generator_id' => $generator->id,
                'user_id' => $user->id,
            ]);
        });

        it('allows reusing login after soft deleting user', function () {
            $user = User::factory()->create([
                'login' => 'employee.login',
                'role' => Role::EMPLOYEE->value,
            ]);
            $manager = User::factory()->create(['role' => Role::MANAGER->value]);

            $deleteResponse = $this->actingAs($manager, 'sanctum')
                ->deleteJson("/api/v1/users/{$user->id}");
            $deleteResponse->assertStatus(200);

            $createResponse = $this->actingAs($manager, 'sanctum')
                ->postJson('/api/v1/users', [
                    'login' => 'employee.login',
                    'password' => 'SecurePassword123!',
                    'password_confirmation' => 'SecurePassword123!',
                    'full_name' => 'Replacement Employee',
                    'role' => Role::EMPLOYEE->value,
                    'phone' => '+998901234567',
                ]);

            $createResponse->assertStatus(201);
            $this->assertDatabaseHas('users', [
                'login' => 'employee.login',
                'deleted_at' => null,
            ]);
        });

        it('requires manager or owner role for deletion', function () {
            // Arrange
            $user = User::factory()->create();
            $employee = User::factory()->create(['role' => Role::EMPLOYEE->value]);

            // Act
            $response = $this->actingAs($employee, 'sanctum')
                ->deleteJson("/api/v1/users/{$user->id}");

            // Assert
            expect($response->status())->toBe(403);
        });
    });

    describe('GET /api/v1/users/{id}/status', function () {
        it('returns user status information', function () {
            // Arrange
            $user = User::factory()->create();
            $manager = User::factory()->create(['role' => Role::MANAGER->value]);

            // Act
            $response = $this->actingAs($manager, 'sanctum')
                ->getJson("/api/v1/users/{$user->id}/status");

            // Assert
            $response->assertStatus(200);
        });
    });

    describe('Password change scenarios', function () {
        it('allows manager to change employee password without current_password', function () {
            // Arrange
            $employee = User::factory()->create([
                'role' => Role::EMPLOYEE->value,
                'password' => bcrypt('OldPassword123!'),
            ]);
            $manager = User::factory()->create(['role' => Role::MANAGER->value]);

            // Act
            $response = $this->actingAs($manager, 'sanctum')
                ->putJson("/api/v1/users/{$employee->id}", [
                    'password' => 'NewPassword123!',
                ]);

            // Assert
            $response->assertStatus(200);
        });

        it('requires current_password when user changes own password', function () {
            // Arrange
            $manager = User::factory()->create([
                'role' => Role::MANAGER->value,
                'password' => bcrypt('OldPassword123!'),
            ]);

            // Act - попытка изменить пароль без current_password
            $response = $this->actingAs($manager, 'sanctum')
                ->putJson("/api/v1/users/{$manager->id}", [
                    'password' => 'NewPassword123!',
                ]);

            // Assert
            $response->assertStatus(422);
            $response->assertJsonPath('message', 'Текущий пароль указан неверно');
        });

        it('rejects wrong current_password when user changes own password', function () {
            // Arrange
            $manager = User::factory()->create([
                'role' => Role::MANAGER->value,
                'password' => bcrypt('OldPassword123!'),
            ]);

            // Act
            $response = $this->actingAs($manager, 'sanctum')
                ->putJson("/api/v1/users/{$manager->id}", [
                    'current_password' => 'WrongPassword!',
                    'password' => 'NewPassword123!',
                ]);

            // Assert
            $response->assertStatus(422);
        });

        it('allows user to change own password with correct current_password', function () {
            // Arrange
            $manager = User::factory()->create([
                'role' => Role::MANAGER->value,
                'password' => bcrypt('OldPassword123!'),
            ]);

            // Act
            $response = $this->actingAs($manager, 'sanctum')
                ->putJson("/api/v1/users/{$manager->id}", [
                    'current_password' => 'OldPassword123!',
                    'password' => 'NewPassword123!',
                ]);

            // Assert
            $response->assertStatus(200);
        });

        it('allows owner to change manager password without current_password', function () {
            // Arrange
            $manager = User::factory()->create([
                'role' => Role::MANAGER->value,
                'password' => bcrypt('OldPassword123!'),
            ]);
            $owner = User::factory()->create(['role' => Role::OWNER->value]);

            // Act
            $response = $this->actingAs($owner, 'sanctum')
                ->putJson("/api/v1/users/{$manager->id}", [
                    'password' => 'NewPassword123!',
                ]);

            // Assert
            $response->assertStatus(200);
        });
    });

    describe('Self-editing restrictions', function () {
        it('prevents user from changing own role', function () {
            // Arrange
            $manager = User::factory()->create(['role' => Role::MANAGER->value]);

            // Act
            $response = $this->actingAs($manager, 'sanctum')
                ->putJson("/api/v1/users/{$manager->id}", [
                    'role' => Role::OWNER->value,
                ]);

            // Assert
            $response->assertStatus(403);
            $response->assertJsonPath('error_type', 'self_edit_restricted');
        });

        it('prevents user from changing own dealership_id', function () {
            // Arrange
            $dealership = \App\Models\AutoDealership::factory()->create();
            $manager = User::factory()->create(['role' => Role::MANAGER->value]);

            // Act
            $response = $this->actingAs($manager, 'sanctum')
                ->putJson("/api/v1/users/{$manager->id}", [
                    'dealership_id' => $dealership->id,
                ]);

            // Assert
            $response->assertStatus(403);
            $response->assertJsonPath('error_type', 'self_edit_restricted');
        });

        it('allows user to change own name and phone', function () {
            // Arrange
            $manager = User::factory()->create([
                'role' => Role::MANAGER->value,
                'full_name' => 'Old Name',
                'phone' => '+998901111111',
            ]);

            // Act
            $response = $this->actingAs($manager, 'sanctum')
                ->putJson("/api/v1/users/{$manager->id}", [
                    'full_name' => 'New Name',
                    'phone' => '+998902222222',
                ]);

            // Assert
            $response->assertStatus(200);
            $this->assertDatabaseHas('users', [
                'id' => $manager->id,
                'full_name' => 'New Name',
                'phone' => '+998902222222',
            ]);
        });

        it('prevents user from deleting themselves', function () {
            // Arrange
            $manager = User::factory()->create(['role' => Role::MANAGER->value]);

            // Act
            $response = $this->actingAs($manager, 'sanctum')
                ->deleteJson("/api/v1/users/{$manager->id}");

            // Assert
            $response->assertStatus(403);
            $response->assertJsonPath('message', 'Вы не можете удалить свой собственный аккаунт');
        });
    });

    describe('Manager cannot edit/delete other managers', function () {
        it('prevents manager from editing another manager', function () {
            // Arrange
            $manager1 = User::factory()->create(['role' => Role::MANAGER->value]);
            $manager2 = User::factory()->create(['role' => Role::MANAGER->value]);

            // Act
            $response = $this->actingAs($manager1, 'sanctum')
                ->putJson("/api/v1/users/{$manager2->id}", [
                    'full_name' => 'New Name',
                ]);

            // Assert
            $response->assertStatus(403);
            $response->assertJsonPath('error_type', 'access_denied');
        });

        it('prevents manager from deleting another manager', function () {
            // Arrange
            $manager1 = User::factory()->create(['role' => Role::MANAGER->value]);
            $manager2 = User::factory()->create(['role' => Role::MANAGER->value]);

            // Act
            $response = $this->actingAs($manager1, 'sanctum')
                ->deleteJson("/api/v1/users/{$manager2->id}");

            // Assert
            $response->assertStatus(403);
        });

        it('allows owner to edit manager', function () {
            // Arrange
            $manager = User::factory()->create([
                'role' => Role::MANAGER->value,
                'full_name' => 'Old Name',
            ]);
            $owner = User::factory()->create(['role' => Role::OWNER->value]);

            // Act
            $response = $this->actingAs($owner, 'sanctum')
                ->putJson("/api/v1/users/{$manager->id}", [
                    'full_name' => 'New Name',
                ]);

            // Assert
            $response->assertStatus(200);
            $this->assertDatabaseHas('users', [
                'id' => $manager->id,
                'full_name' => 'New Name',
            ]);
        });

        it('allows owner to delete manager', function () {
            // Arrange
            $manager = User::factory()->create(['role' => Role::MANAGER->value]);
            $owner = User::factory()->create(['role' => Role::OWNER->value]);

            // Act
            $response = $this->actingAs($owner, 'sanctum')
                ->deleteJson("/api/v1/users/{$manager->id}");

            // Assert
            $response->assertStatus(200);
        });
    });
});
