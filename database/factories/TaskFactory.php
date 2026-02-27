<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Task;
use App\Models\User;
use App\Models\AutoDealership;
use Illuminate\Database\Eloquent\Factories\Factory;

class TaskFactory extends Factory
{
    protected $model = Task::class;

    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'description' => fake()->paragraph(),
            'comment' => fake()->optional()->sentence(),
            'creator_id' => User::factory(),
            'dealership_id' => AutoDealership::factory(),
            'appear_date' => fake()->dateTimeBetween('-7 days', 'now'),
            'deadline' => fake()->dateTimeBetween('now', '+30 days'),
            'task_type' => fake()->randomElement(['individual', 'group']),
            'response_type' => fake()->randomElement(['notification', 'completion', 'completion_with_proof']),
            'priority' => fake()->randomElement(['low', 'medium', 'high']),
            'tags' => fake()->optional(0.6)->randomElements(['важное', 'срочное', 'рутина', 'продажа', 'клиент'], rand(1, 3)),
            'is_active' => true,
            'postpone_count' => 0,
            'archived_at' => null,
        ];
    }

    /**
     * Задача с типом notification (уведомление).
     */
    public function notification(): static
    {
        return $this->state(fn (array $attributes) => [
            'response_type' => 'notification',
        ]);
    }

    /**
     * Задача с типом completion (выполнение).
     */
    public function completion(): static
    {
        return $this->state(fn (array $attributes) => [
            'response_type' => 'completion',
        ]);
    }

    /**
     * Задача с типом completion_with_proof, требующая доказательства.
     */
    public function completionWithProof(): static
    {
        return $this->state(fn (array $attributes) => [
            'response_type' => 'completion_with_proof',
        ]);
    }

    /**
     * Архивированная задача.
     */
    public function archived(?string $reason = 'completed'): static
    {
        return $this->state(fn (array $attributes) => [
            'archived_at' => now(),
            'archive_reason' => $reason,
            'is_active' => false,
        ]);
    }

    /**
     * Индивидуальная задача.
     */
    public function individual(): static
    {
        return $this->state(fn (array $attributes) => [
            'task_type' => 'individual',
        ]);
    }

    /**
     * Групповая задача.
     */
    public function group(): static
    {
        return $this->state(fn (array $attributes) => [
            'task_type' => 'group',
        ]);
    }

    /**
     * Высокий приоритет.
     */
    public function highPriority(): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => 'high',
        ]);
    }

    /**
     * Просроченная задача.
     */
    public function overdue(): static
    {
        return $this->state(fn (array $attributes) => [
            'appear_date' => fake()->dateTimeBetween('-14 days', '-7 days'),
            'deadline' => fake()->dateTimeBetween('-3 days', '-1 day'),
            'is_active' => true,
        ]);
    }
}
