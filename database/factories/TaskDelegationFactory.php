<?php

namespace Database\Factories;

use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TaskDelegation>
 */
class TaskDelegationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'from_user_id' => User::factory(),
            'to_user_id' => User::factory(),
            'status' => 'pending',
            'reason' => fake()->optional()->sentence(),
        ];
    }

    /**
     * Принятый запрос.
     */
    public function accepted(): static
    {
        return $this->state(fn () => [
            'status' => 'accepted',
            'responded_at' => now(),
        ]);
    }

    /**
     * Отклонённый запрос.
     */
    public function rejected(): static
    {
        return $this->state(fn () => [
            'status' => 'rejected',
            'rejection_reason' => fake()->sentence(),
            'responded_at' => now(),
        ]);
    }

    /**
     * Отменённый запрос.
     */
    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => 'cancelled',
            'responded_at' => now(),
        ]);
    }
}
