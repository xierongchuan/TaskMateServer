<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AutoDealership;
use Illuminate\Database\Eloquent\Factories\Factory;

class AutoDealershipFactory extends Factory
{
    protected $model = AutoDealership::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'address' => fake()->address(),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
