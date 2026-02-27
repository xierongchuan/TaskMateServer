<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Setting;
use App\Models\AutoDealership;
use Illuminate\Database\Eloquent\Factories\Factory;

class SettingFactory extends Factory
{
    protected $model = Setting::class;

    public function definition(): array
    {
        return [
            'key' => fake()->unique()->word(),
            'value' => fake()->word(),
            'type' => 'string',
            'description' => fake()->optional()->sentence(),
            'dealership_id' => null,
        ];
    }

    public function integer(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'integer',
            'value' => (string) fake()->numberBetween(1, 100),
        ]);
    }

    public function boolean(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'boolean',
            'value' => fake()->boolean() ? '1' : '0',
        ]);
    }

    public function forDealership(AutoDealership $dealership): static
    {
        return $this->state(fn (array $attributes) => [
            'dealership_id' => $dealership->id,
        ]);
    }
}
