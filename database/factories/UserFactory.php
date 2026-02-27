<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Enums\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'login' => fake()->unique()->userName(),
            'full_name' => fake()->name(),
            'phone' => fake()->phoneNumber(),
            'role' => fake()->randomElement([Role::EMPLOYEE->value, Role::MANAGER->value, Role::OBSERVER->value]),
            'password' => Hash::make('password123'),
        ];
    }

    /**
     * Indicate that the user should have a specific role.
     */
    public function role(Role $role): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => $role->value,
        ]);
    }


}
