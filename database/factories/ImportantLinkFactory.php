<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AutoDealership;
use App\Models\ImportantLink;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ImportantLinkFactory extends Factory
{
    protected $model = ImportantLink::class;

    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'url' => fake()->url(),
            'description' => fake()->optional()->paragraph(),
            'dealership_id' => AutoDealership::factory(),
            'creator_id' => User::factory(),
            'sort_order' => fake()->numberBetween(0, 100),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function global(): static
    {
        return $this->state(fn (array $attributes) => [
            'dealership_id' => null,
        ]);
    }
}
