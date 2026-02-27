<?php

declare(strict_types=1);

use App\Models\Shift;
use App\Models\User;
use App\Models\AutoDealership;

describe('Shift Model', function () {
    it('belongs to user', function () {
        $user = User::factory()->create();
        $shift = Shift::factory()->create(['user_id' => $user->id]);

        expect($shift->user->id)->toBe($user->id);
    });

    it('belongs to dealership', function () {
        $dealership = AutoDealership::factory()->create();
        $shift = Shift::factory()->create(['dealership_id' => $dealership->id]);

        expect($shift->dealership->id)->toBe($dealership->id);
    });
});
