<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AutoDealership;
use App\Models\TaskGenerator;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskGenerator>
 */
class TaskGeneratorFactory extends Factory
{
    protected $model = TaskGenerator::class;

    public function definition(): array
    {
        $recurrence = fake()->randomElement(['daily', 'weekly', 'monthly']);

        return [
            'title' => fake()->sentence(3),
            'description' => fake()->optional()->paragraph(),
            'creator_id' => User::factory(),
            'dealership_id' => AutoDealership::factory(),
            'recurrence' => $recurrence,
            'recurrence_time' => '09:00',
            'deadline_time' => '18:00',
            'recurrence_days_of_week' => $recurrence === 'weekly' ? [fake()->numberBetween(1, 7)] : null,
            'recurrence_days_of_month' => $recurrence === 'monthly' ? [fake()->numberBetween(1, 28)] : null,
            'start_date' => Carbon::today(),
            'end_date' => null,
            'task_type' => fake()->randomElement(['individual', 'group']),
            'response_type' => fake()->randomElement(['notification', 'completion', 'completion_with_proof']),
            'priority' => fake()->randomElement(['low', 'medium', 'high']),
            'tags' => [],
            'notification_settings' => null,
            'is_active' => true,
            'last_generated_at' => null,
        ];
    }

    /**
     * Configure the model factory after creating.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (TaskGenerator $generator) {
            // Создаём хотя бы одного исполнителя для всех генераторов
            // чтобы избежать проблем с валидацией
            $user = User::where('dealership_id', $generator->dealership_id)->first()
                ?? User::factory()->create(['dealership_id' => $generator->dealership_id]);

            $generator->assignments()->create([
                'user_id' => $user->id,
            ]);
        });
    }

    /**
     * Indicate that the generator is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Set recurrence to daily.
     */
    public function daily(): static
    {
        return $this->state(fn (array $attributes) => [
            'recurrence' => 'daily',
            'recurrence_days_of_week' => null,
            'recurrence_days_of_month' => null,
        ]);
    }

    /**
     * Set recurrence to weekly with specified days.
     */
    public function weekly(int|array $daysOfWeek = [1]): static
    {
        $days = is_array($daysOfWeek) ? $daysOfWeek : [$daysOfWeek];

        return $this->state(fn (array $attributes) => [
            'recurrence' => 'weekly',
            'recurrence_days_of_week' => $days,
            'recurrence_days_of_month' => null,
        ]);
    }

    /**
     * Set recurrence to monthly with specified days.
     */
    public function monthly(int|array $daysOfMonth = [1]): static
    {
        $days = is_array($daysOfMonth) ? $daysOfMonth : [$daysOfMonth];

        return $this->state(fn (array $attributes) => [
            'recurrence' => 'monthly',
            'recurrence_days_of_week' => null,
            'recurrence_days_of_month' => $days,
        ]);
    }
}
