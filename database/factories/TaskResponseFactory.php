<?php

namespace Database\Factories;

use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TaskResponse>
 */
class TaskResponseFactory extends Factory
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
            'user_id' => User::factory(),
            'status' => fake()->randomElement(['pending', 'acknowledged', 'pending_review', 'completed', 'rejected']),
            'comment' => fake()->sentence(),
            'responded_at' => now(),
        ];
    }
}
