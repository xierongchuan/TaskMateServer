<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\AutoDealership;
use App\Http\Middleware\CheckRole;
use App\Enums\Role;
use Illuminate\Http\Request;

describe('CheckRole Middleware', function () {
    beforeEach(function () {
        $this->dealership = AutoDealership::factory()->create();
        $this->owner = User::factory()->create([
            'role' => Role::OWNER->value,
            'dealership_id' => $this->dealership->id
        ]);
        $this->manager = User::factory()->create([
            'role' => Role::MANAGER->value,
            'dealership_id' => $this->dealership->id
        ]);
        $this->employee = User::factory()->create([
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $this->dealership->id
        ]);
        $this->observer = User::factory()->create([
            'role' => Role::OBSERVER->value,
            'dealership_id' => $this->dealership->id
        ]);
        $this->middleware = new CheckRole();
    });

    describe('handle', function () {
        it('returns 401 for unauthenticated user', function () {
            // Arrange
            $request = Request::create('/test', 'GET');

            // Act
            $response = $this->middleware->handle($request, fn () => response('OK'), 'owner');

            // Assert
            expect($response->getStatusCode())->toBe(401);
            expect($response->getData()->message)->toBe('Не авторизован');
        });

        it('allows access for user with exact role', function () {
            // Arrange
            $request = Request::create('/test', 'GET');
            $request->setUserResolver(fn () => $this->owner);

            // Act
            $response = $this->middleware->handle($request, fn () => response('OK'), 'owner');

            // Assert
            expect($response->getStatusCode())->toBe(200);
            expect($response->getContent())->toBe('OK');
        });

        it('allows access for user with one of allowed roles', function () {
            // Arrange
            $request = Request::create('/test', 'GET');
            $request->setUserResolver(fn () => $this->manager);

            // Act
            $response = $this->middleware->handle($request, fn () => response('OK'), 'manager', 'owner');

            // Assert
            expect($response->getStatusCode())->toBe(200);
        });

        it('denies access for user without required role', function () {
            // Arrange
            $request = Request::create('/test', 'GET');
            $request->setUserResolver(fn () => $this->employee);

            // Act
            $response = $this->middleware->handle($request, fn () => response('OK'), 'owner');

            // Assert
            expect($response->getStatusCode())->toBe(403);
            expect($response->getData()->message)->toBe('Недостаточно прав для выполнения этого действия');
        });

        it('returns required roles in error response', function () {
            // Arrange
            $request = Request::create('/test', 'GET');
            $request->setUserResolver(fn () => $this->employee);

            // Act
            $response = $this->middleware->handle($request, fn () => response('OK'), 'manager', 'owner');

            // Assert
            $data = $response->getData();
            expect($data->required_roles)->toContain('manager', 'owner');
            expect($data->your_role)->toBe('employee');
        });

        it('works with Role enum values', function () {
            // Arrange
            $request = Request::create('/test', 'GET');
            $request->setUserResolver(fn () => $this->owner);

            // Act
            $response = $this->middleware->handle($request, fn () => response('OK'), 'owner');

            // Assert
            expect($response->getStatusCode())->toBe(200);
        });
    });

    describe('hasRoleOrHigher static method', function () {
        it('returns true when user has exact role', function () {
            expect(CheckRole::hasRoleOrHigher('manager', 'manager'))->toBeTrue();
        });

        it('returns true when user has higher role', function () {
            expect(CheckRole::hasRoleOrHigher('owner', 'manager'))->toBeTrue();
            expect(CheckRole::hasRoleOrHigher('owner', 'employee'))->toBeTrue();
            expect(CheckRole::hasRoleOrHigher('manager', 'employee'))->toBeTrue();
        });

        it('returns false when user has lower role', function () {
            expect(CheckRole::hasRoleOrHigher('employee', 'manager'))->toBeFalse();
            expect(CheckRole::hasRoleOrHigher('employee', 'owner'))->toBeFalse();
            expect(CheckRole::hasRoleOrHigher('manager', 'owner'))->toBeFalse();
        });

        it('returns false for unknown role', function () {
            expect(CheckRole::hasRoleOrHigher('unknown', 'owner'))->toBeFalse();
        });

        it('follows correct hierarchy', function () {
            // owner > manager > observer > employee
            expect(CheckRole::hasRoleOrHigher('owner', 'manager'))->toBeTrue();
            expect(CheckRole::hasRoleOrHigher('manager', 'observer'))->toBeTrue();
            expect(CheckRole::hasRoleOrHigher('observer', 'employee'))->toBeTrue();
        });
    });

    describe('integration tests', function () {
        it('protects route for owner only', function () {
            // Act as employee
            $response = $this->actingAs($this->employee, 'sanctum')
                ->getJson('/api/v1/audit-logs');

            // Assert - should be forbidden
            $response->assertStatus(403);
        });

        it('allows owner access to protected route', function () {
            // Act as owner
            $response = $this->actingAs($this->owner, 'sanctum')
                ->getJson('/api/v1/audit-logs');

            // Assert - should be allowed
            $response->assertStatus(200);
        });

        it('allows manager access to manager routes', function () {
            // Act as manager - calendar update requires manager or owner
            $response = $this->actingAs($this->manager, 'sanctum')
                ->putJson('/api/v1/calendar/2025-01-01', [
                    'type' => 'holiday',
                ]);

            // Assert - should be allowed
            $response->assertStatus(200);
        });

        it('denies employee access to manager routes', function () {
            // Act as employee - calendar update requires manager or owner
            $response = $this->actingAs($this->employee, 'sanctum')
                ->putJson('/api/v1/calendar/2025-01-01', [
                    'type' => 'holiday',
                ]);

            // Assert - should be forbidden
            $response->assertStatus(403);
        });
    });
});
