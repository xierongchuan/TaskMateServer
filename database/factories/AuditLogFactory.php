<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\AutoDealership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AuditLog>
 */
class AuditLogFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\Illuminate\Database\Eloquent\Model>
     */
    protected $model = AuditLog::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'table_name' => $this->faker->randomElement([
                'tasks',
                'users',
                'shifts',
                'task_responses',
                'auto_dealerships',
            ]),
            'record_id' => $this->faker->numberBetween(1, 1000),
            'actor_id' => User::factory(),
            'dealership_id' => $this->faker->optional(0.7)->passthrough(AutoDealership::factory()),
            'action' => $this->faker->randomElement(['created', 'updated', 'deleted']),
            'payload' => [
                'field1' => $this->faker->word(),
                'field2' => $this->faker->sentence(),
                'changed' => [
                    'old' => $this->faker->word(),
                    'new' => $this->faker->word(),
                ],
            ],
            'created_at' => $this->faker->dateTimeBetween('-30 days', 'now'),
        ];
    }

    /**
     * Создать лог для задачи.
     */
    public function forTask(): static
    {
        return $this->state(fn (array $attributes) => [
            'table_name' => 'tasks',
        ]);
    }

    /**
     * Создать лог для пользователя.
     */
    public function forUser(): static
    {
        return $this->state(fn (array $attributes) => [
            'table_name' => 'users',
        ]);
    }

    /**
     * Создать лог для смены.
     */
    public function forShift(): static
    {
        return $this->state(fn (array $attributes) => [
            'table_name' => 'shifts',
        ]);
    }

    /**
     * Создать лог создания записи.
     */
    public function created(): static
    {
        return $this->state(fn (array $attributes) => [
            'action' => 'created',
        ]);
    }

    /**
     * Создать лог обновления записи.
     */
    public function updated(): static
    {
        return $this->state(fn (array $attributes) => [
            'action' => 'updated',
        ]);
    }

    /**
     * Создать лог удаления записи.
     */
    public function deleted(): static
    {
        return $this->state(fn (array $attributes) => [
            'action' => 'deleted',
        ]);
    }

    /**
     * Создать лог без автосалона.
     */
    public function withoutDealership(): static
    {
        return $this->state(fn (array $attributes) => [
            'dealership_id' => null,
        ]);
    }

    /**
     * Создать лог без актора (системное действие).
     */
    public function withoutActor(): static
    {
        return $this->state(fn (array $attributes) => [
            'actor_id' => null,
        ]);
    }
}
