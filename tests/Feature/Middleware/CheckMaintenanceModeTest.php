<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

describe('CheckMaintenanceMode Middleware', function () {
    beforeEach(function () {
        // Очистить кэш настроек перед каждым тестом
        Cache::flush();
    });

    describe('When maintenance mode is disabled', function () {
        it('allows all users to access the system', function () {
            // Arrange - создаём пользователя
            $user = User::factory()->create([
                'role' => Role::EMPLOYEE->value,
            ]);

            // Act - пытаемся получить доступ к API
            $response = $this->actingAs($user, 'sanctum')
                ->getJson('/api/v1/session/current');

            // Assert - доступ разрешен
            expect($response->status())->toBe(200);
        });

        it('allows unauthenticated requests', function () {
            // Act - пытаемся получить доступ без авторизации
            $response = $this->getJson('/api/v1/session/current');

            // Assert - получаем 401 (не авторизован), а не 503 (обслуживание)
            expect($response->status())->toBe(401);
        });
    });

    describe('When maintenance mode is enabled', function () {
        beforeEach(function () {
            // Включаем режим обслуживания глобально (dealership_id = null)
            Setting::updateOrCreate(
                ['key' => 'maintenance_mode', 'dealership_id' => null],
                ['value' => '1', 'type' => 'boolean']
            );
            Cache::flush(); // Очищаем кэш после установки настройки
        });

        it('blocks employee access with 503 status', function () {
            // Arrange
            $employee = User::factory()->create([
                'role' => Role::EMPLOYEE->value,
            ]);

            // Act
            $response = $this->actingAs($employee, 'sanctum')
                ->getJson('/api/v1/session/current');

            // Assert
            expect($response->status())->toBe(503)
                ->and($response->json('success'))->toBe(false)
                ->and($response->json('error_type'))->toBe('maintenance_mode')
                ->and($response->json('message'))->toContain('временно недоступна');
        });

        it('blocks observer access with 503 status', function () {
            // Arrange
            $observer = User::factory()->create([
                'role' => Role::OBSERVER->value,
            ]);

            // Act
            $response = $this->actingAs($observer, 'sanctum')
                ->getJson('/api/v1/session/current');

            // Assert
            expect($response->status())->toBe(503)
                ->and($response->json('error_type'))->toBe('maintenance_mode');
        });

        it('blocks manager access with 503 status', function () {
            // Arrange
            $manager = User::factory()->create([
                'role' => Role::MANAGER->value,
            ]);

            // Act
            $response = $this->actingAs($manager, 'sanctum')
                ->getJson('/api/v1/session/current');

            // Assert
            expect($response->status())->toBe(503)
                ->and($response->json('error_type'))->toBe('maintenance_mode');
        });

        it('allows owner access during maintenance', function () {
            // Arrange
            $owner = User::factory()->create([
                'role' => Role::OWNER->value,
            ]);

            // Act
            $response = $this->actingAs($owner, 'sanctum')
                ->getJson('/api/v1/session/current');

            // Assert - владелец может войти
            expect($response->status())->toBe(200)
                ->and($response->json('user'))->toBeArray()
                ->and($response->json('user.role'))->toBe('owner');
        });

        it('blocks unauthenticated login attempts with 503', function () {
            // Arrange - создаём пользователя для попытки входа
            $user = User::factory()->create([
                'login' => 'testuser',
                'password' => \Illuminate\Support\Facades\Hash::make('password'),
                'role' => Role::EMPLOYEE->value,
            ]);

            // Act - попытка войти (endpoint /session не требует auth)
            $response = $this->postJson('/api/v1/session', [
                'login' => 'testuser',
                'password' => 'password',
            ]);

            // Assert - даже логин блокируется в режиме обслуживания
            expect($response->status())->toBe(503)
                ->and($response->json('error_type'))->toBe('maintenance_mode');
        });

        it('allows owner to change settings during maintenance', function () {
            // Arrange
            $owner = User::factory()->create([
                'role' => Role::OWNER->value,
            ]);

            // Act - владелец пытается изменить настройки через общий settings endpoint
            $response = $this->actingAs($owner, 'sanctum')
                ->putJson('/api/v1/settings/maintenance_mode', [
                    'value' => false, // Выключаем режим обслуживания
                    'type' => 'boolean',
                ]);

            // Assert - владелец может изменить настройки
            expect($response->status())->toBe(200);

            // Verify maintenance mode is now disabled
            Cache::flush();
            $setting = Setting::where('key', 'maintenance_mode')
                ->whereNull('dealership_id')
                ->first();
            expect($setting->getTypedValue())->toBe(false);
        });
    });

    describe('Maintenance mode scope', function () {
        it('is always global regardless of dealership_id in request', function () {
            // Arrange - создаём дилера и сотрудника
            $dealership = \App\Models\AutoDealership::factory()->create();
            $employee = User::factory()->create([
                'role' => Role::EMPLOYEE->value,
                'dealership_id' => $dealership->id,
            ]);

            // Включаем режим обслуживания глобально
            Setting::updateOrCreate(
                ['key' => 'maintenance_mode', 'dealership_id' => null],
                ['value' => '1', 'type' => 'boolean']
            );
            Cache::flush();

            // Act - сотрудник пытается получить доступ
            $response = $this->actingAs($employee, 'sanctum')
                ->getJson('/api/v1/session/current');

            // Assert - доступ заблокирован глобально
            expect($response->status())->toBe(503)
                ->and($response->json('error_type'))->toBe('maintenance_mode');
        });

        it('ignores dealership-specific maintenance_mode settings', function () {
            // Arrange - создаём дилера
            $dealership = \App\Models\AutoDealership::factory()->create();

            // Пытаемся создать настройку для конкретного дилера (не должна влиять)
            Setting::updateOrCreate(
                ['key' => 'maintenance_mode', 'dealership_id' => $dealership->id],
                ['value' => '1', 'type' => 'boolean']
            );

            // Глобальная настройка выключена
            Setting::updateOrCreate(
                ['key' => 'maintenance_mode', 'dealership_id' => null],
                ['value' => '0', 'type' => 'boolean']
            );
            Cache::flush();

            $employee = User::factory()->create([
                'role' => Role::EMPLOYEE->value,
                'dealership_id' => $dealership->id,
            ]);

            // Act - сотрудник пытается получить доступ
            $response = $this->actingAs($employee, 'sanctum')
                ->getJson('/api/v1/session/current');

            // Assert - доступ разрешен (игнорируется настройка дилера, учитывается только глобальная)
            expect($response->status())->toBe(200);
        });
    });
});
