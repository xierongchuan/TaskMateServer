<?php

declare(strict_types=1);

use App\Models\ImportantLink;
use App\Models\AutoDealership;
use App\Models\User;
use App\Enums\Role;

describe('ImportantLink Model', function () {
    beforeEach(function () {
        $this->dealership = AutoDealership::factory()->create();
        $this->manager = User::factory()->create([
            'role' => Role::MANAGER->value,
            'dealership_id' => $this->dealership->id
        ]);
    });

    it('can be created', function () {
        // Act
        $link = ImportantLink::factory()->create([
            'dealership_id' => $this->dealership->id,
            'title' => 'Корпоративный портал',
            'url' => 'https://portal.example.com',
            'description' => 'Внутренний портал компании',
        ]);

        // Assert
        expect($link)->toBeInstanceOf(ImportantLink::class);
        expect($link->title)->toBe('Корпоративный портал');
        expect($link->url)->toBe('https://portal.example.com');
    });

    describe('dealership relationship', function () {
        it('belongs to dealership', function () {
            // Arrange
            $link = ImportantLink::factory()->create([
                'dealership_id' => $this->dealership->id,
            ]);

            // Act & Assert
            expect($link->dealership)->toBeInstanceOf(AutoDealership::class);
            expect($link->dealership->id)->toBe($this->dealership->id);
        });
    });

    describe('creator relationship', function () {
        it('belongs to creator', function () {
            // Arrange
            $link = ImportantLink::factory()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);

            // Act & Assert
            expect($link->creator)->toBeInstanceOf(User::class);
            expect($link->creator->id)->toBe($this->manager->id);
        });
    });

    describe('scope active', function () {
        it('filters active links', function () {
            // Arrange
            ImportantLink::factory()->create([
                'dealership_id' => $this->dealership->id,
                'is_active' => true,
            ]);
            ImportantLink::factory()->create([
                'dealership_id' => $this->dealership->id,
                'is_active' => false,
            ]);

            // Act
            $activeLinks = ImportantLink::where('is_active', true)->get();

            // Assert
            expect($activeLinks)->toHaveCount(1);
        });
    });

    describe('sort_order', function () {
        it('stores sort order for sorting', function () {
            // Arrange
            $link1 = ImportantLink::factory()->create([
                'dealership_id' => $this->dealership->id,
                'sort_order' => 1,
            ]);
            $link2 = ImportantLink::factory()->create([
                'dealership_id' => $this->dealership->id,
                'sort_order' => 2,
            ]);

            // Act
            $orderedLinks = ImportantLink::orderBy('sort_order')->get();

            // Assert
            expect($orderedLinks[0]->id)->toBe($link1->id);
            expect($orderedLinks[1]->id)->toBe($link2->id);
        });
    });
});
