<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Task;
use App\Models\AutoDealership;
use App\Traits\HasDealershipAccess;
use App\Enums\Role;

// Create a test class that uses the trait
class HasDealershipAccessTestClass
{
    use HasDealershipAccess;

    // Expose protected methods for testing
    public function testIsOwner(User $user): bool
    {
        return $this->isOwner($user);
    }

    public function testGetAccessibleDealershipIds(User $user): array
    {
        return $this->getAccessibleDealershipIds($user);
    }

    public function testHasAccessToDealership(User $user, int $dealershipId): bool
    {
        return $this->hasAccessToDealership($user, $dealershipId);
    }

    public function testValidateDealershipAccess(User $user, ?int $dealershipId)
    {
        return $this->validateDealershipAccess($user, $dealershipId);
    }

    public function testValidateMultipleDealershipsAccess(User $user, array $dealershipIds)
    {
        return $this->validateMultipleDealershipsAccess($user, $dealershipIds);
    }

    public function testHasAccessToUser(User $currentUser, User $targetUser): bool
    {
        return $this->hasAccessToUser($currentUser, $targetUser);
    }

    public function testGetUserDealershipIds(User $user): array
    {
        return $this->getUserDealershipIds($user);
    }
}

describe('HasDealershipAccess Trait', function () {
    beforeEach(function () {
        $this->dealership = AutoDealership::factory()->create();
        $this->otherDealership = AutoDealership::factory()->create();

        $this->owner = User::factory()->create([
            'role' => Role::OWNER->value,
        ]);
        $this->manager = User::factory()->create([
            'role' => Role::MANAGER->value,
            'dealership_id' => $this->dealership->id
        ]);
        $this->employee = User::factory()->create([
            'role' => Role::EMPLOYEE->value,
            'dealership_id' => $this->dealership->id
        ]);

        $this->trait = new HasDealershipAccessTestClass();
    });

    describe('isOwner', function () {
        it('returns true for owner', function () {
            expect($this->trait->testIsOwner($this->owner))->toBeTrue();
        });

        it('returns false for manager', function () {
            expect($this->trait->testIsOwner($this->manager))->toBeFalse();
        });

        it('returns false for employee', function () {
            expect($this->trait->testIsOwner($this->employee))->toBeFalse();
        });
    });

    describe('getAccessibleDealershipIds', function () {
        it('returns dealership ids for user', function () {
            // Act
            $ids = $this->trait->testGetAccessibleDealershipIds($this->manager);

            // Assert
            expect($ids)->toContain($this->dealership->id);
        });
    });

    describe('hasAccessToDealership', function () {
        it('returns true for owner with any dealership', function () {
            expect($this->trait->testHasAccessToDealership($this->owner, $this->dealership->id))->toBeTrue();
            expect($this->trait->testHasAccessToDealership($this->owner, $this->otherDealership->id))->toBeTrue();
        });

        it('returns true for user with access', function () {
            expect($this->trait->testHasAccessToDealership($this->manager, $this->dealership->id))->toBeTrue();
        });

        it('returns false for user without access', function () {
            expect($this->trait->testHasAccessToDealership($this->manager, $this->otherDealership->id))->toBeFalse();
        });
    });

    describe('validateDealershipAccess', function () {
        it('returns null for owner', function () {
            $result = $this->trait->testValidateDealershipAccess($this->owner, $this->dealership->id);
            expect($result)->toBeNull();
        });

        it('returns null for null dealership', function () {
            $result = $this->trait->testValidateDealershipAccess($this->manager, null);
            expect($result)->toBeNull();
        });

        it('returns null for accessible dealership', function () {
            $result = $this->trait->testValidateDealershipAccess($this->manager, $this->dealership->id);
            expect($result)->toBeNull();
        });

        it('returns 403 response for inaccessible dealership', function () {
            $result = $this->trait->testValidateDealershipAccess($this->manager, $this->otherDealership->id);
            expect($result)->toBeInstanceOf(\Illuminate\Http\JsonResponse::class);
            expect($result->getStatusCode())->toBe(403);
        });
    });

    describe('validateMultipleDealershipsAccess', function () {
        it('returns null for owner', function () {
            $result = $this->trait->testValidateMultipleDealershipsAccess(
                $this->owner,
                [$this->dealership->id, $this->otherDealership->id]
            );
            expect($result)->toBeNull();
        });

        it('returns null for empty array', function () {
            $result = $this->trait->testValidateMultipleDealershipsAccess($this->manager, []);
            expect($result)->toBeNull();
        });

        it('returns null when all dealerships accessible', function () {
            $result = $this->trait->testValidateMultipleDealershipsAccess(
                $this->manager,
                [$this->dealership->id]
            );
            expect($result)->toBeNull();
        });

        it('returns 403 when some dealerships inaccessible', function () {
            $result = $this->trait->testValidateMultipleDealershipsAccess(
                $this->manager,
                [$this->dealership->id, $this->otherDealership->id]
            );
            expect($result)->toBeInstanceOf(\Illuminate\Http\JsonResponse::class);
            expect($result->getStatusCode())->toBe(403);
        });
    });

    describe('hasAccessToUser', function () {
        it('returns true for owner', function () {
            $targetUser = User::factory()->create([
                'dealership_id' => $this->otherDealership->id
            ]);
            expect($this->trait->testHasAccessToUser($this->owner, $targetUser))->toBeTrue();
        });

        it('returns true for user in same dealership', function () {
            $targetUser = User::factory()->create([
                'dealership_id' => $this->dealership->id
            ]);
            expect($this->trait->testHasAccessToUser($this->manager, $targetUser))->toBeTrue();
        });

        it('returns false for user in different dealership', function () {
            $targetUser = User::factory()->create([
                'dealership_id' => $this->otherDealership->id
            ]);
            expect($this->trait->testHasAccessToUser($this->manager, $targetUser))->toBeFalse();
        });

        it('returns true for user without dealership (orphan)', function () {
            $targetUser = User::factory()->create([
                'dealership_id' => null
            ]);
            expect($this->trait->testHasAccessToUser($this->manager, $targetUser))->toBeTrue();
        });
    });

    describe('getUserDealershipIds', function () {
        it('returns primary dealership id', function () {
            $ids = $this->trait->testGetUserDealershipIds($this->manager);
            expect($ids)->toContain($this->dealership->id);
        });

        it('returns empty array for user without dealership', function () {
            $user = User::factory()->create([
                'dealership_id' => null
            ]);
            $ids = $this->trait->testGetUserDealershipIds($user);
            expect($ids)->toBe([]);
        });

        it('includes attached dealerships', function () {
            // Attach additional dealership
            $this->manager->dealerships()->attach($this->otherDealership->id);

            $ids = $this->trait->testGetUserDealershipIds($this->manager);
            expect($ids)->toContain($this->dealership->id);
            expect($ids)->toContain($this->otherDealership->id);
        });
    });
});
