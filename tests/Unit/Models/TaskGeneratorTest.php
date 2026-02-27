<?php

declare(strict_types=1);

use App\Models\TaskGenerator;
use App\Models\TaskGeneratorAssignment;
use App\Models\Task;
use App\Models\AutoDealership;
use App\Models\User;
use App\Enums\Role;
use Carbon\Carbon;

describe('TaskGenerator Model', function () {
    beforeEach(function () {
        $this->dealership = AutoDealership::factory()->create();
        $this->manager = User::factory()->create([
            'role' => Role::MANAGER->value,
            'dealership_id' => $this->dealership->id
        ]);
    });

    it('can be created', function () {
        // Act
        $generator = TaskGenerator::factory()->create([
            'dealership_id' => $this->dealership->id,
            'title' => 'Ежедневная уборка',
            'is_active' => true,
        ]);

        // Assert
        expect($generator)->toBeInstanceOf(TaskGenerator::class);
        expect($generator->title)->toBe('Ежедневная уборка');
        expect($generator->is_active)->toBeTrue();
    });

    describe('dealership relationship', function () {
        it('belongs to dealership', function () {
            // Arrange
            $generator = TaskGenerator::factory()->create([
                'dealership_id' => $this->dealership->id,
            ]);

            // Act & Assert
            expect($generator->dealership)->toBeInstanceOf(AutoDealership::class);
            expect($generator->dealership->id)->toBe($this->dealership->id);
        });
    });

    describe('creator relationship', function () {
        it('belongs to creator', function () {
            // Arrange
            $generator = TaskGenerator::factory()->create([
                'dealership_id' => $this->dealership->id,
                'creator_id' => $this->manager->id,
            ]);

            // Act & Assert
            expect($generator->creator)->toBeInstanceOf(User::class);
            expect($generator->creator->id)->toBe($this->manager->id);
        });
    });

    describe('assignments relationship', function () {
        it('has many assignments', function () {
            // Arrange
            $generator = TaskGenerator::factory()->create([
                'dealership_id' => $this->dealership->id,
            ]);
            $employee = User::factory()->create([
                'role' => Role::EMPLOYEE->value,
                'dealership_id' => $this->dealership->id
            ]);

            // Удаляем существующие assignments если есть
            $generator->assignments()->delete();

            TaskGeneratorAssignment::create([
                'generator_id' => $generator->id,
                'user_id' => $employee->id,
            ]);

            // Act - перезагрузить модель
            $generator->refresh();

            // Assert
            expect($generator->assignments)->toHaveCount(1);
        });
    });

    describe('generatedTasks relationship', function () {
        it('has many generated tasks', function () {
            // Arrange
            $generator = TaskGenerator::factory()->create([
                'dealership_id' => $this->dealership->id,
            ]);
            Task::factory(3)->create([
                'dealership_id' => $this->dealership->id,
                'generator_id' => $generator->id,
            ]);

            // Act - перезагрузить модель с отношением
            $generator->refresh();

            // Assert
            expect($generator->generatedTasks)->toHaveCount(3);
        });
    });

    describe('scope active', function () {
        it('filters only active generators', function () {
            // Arrange
            TaskGenerator::factory()->create([
                'dealership_id' => $this->dealership->id,
                'is_active' => true,
            ]);
            TaskGenerator::factory()->create([
                'dealership_id' => $this->dealership->id,
                'is_active' => false,
            ]);

            // Act
            $activeGenerators = TaskGenerator::where('is_active', true)->get();

            // Assert
            expect($activeGenerators)->toHaveCount(1);
        });
    });
});
