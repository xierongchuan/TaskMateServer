<?php

declare(strict_types=1);

use App\Models\AutoDealership;

describe('AutoDealership Model', function () {
    it('can create dealership', function () {
        $dealership = AutoDealership::factory()->create();

        expect($dealership)->toBeInstanceOf(AutoDealership::class);
    });
});
