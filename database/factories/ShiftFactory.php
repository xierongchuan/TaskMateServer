<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Shift;
use App\Models\User;
use App\Models\AutoDealership;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

class ShiftFactory extends Factory
{
    protected $model = Shift::class;

    public function definition(): array
    {
        $shiftStart = fake()->dateTimeBetween('-30 days', 'now');

        return [
            'user_id' => User::factory(),
            'dealership_id' => AutoDealership::factory(),
            'shift_start' => $shiftStart,
            'shift_end' => null,
            'opening_photo_path' => fake()->optional()->filePath(),
            'closing_photo_path' => null,
            'status' => 'open',
            'late_minutes' => 0,
            'scheduled_start' => Carbon::parse($shiftStart)->setTime(9, 0),
            'scheduled_end' => null,
        ];
    }

    public function late(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'late',
            'late_minutes' => fake()->numberBetween(1, 60),
        ]);
    }

    public function closed(): static
    {
        return $this->state(function (array $attributes) {
            $start = Carbon::parse($attributes['shift_start']);
            return [
                'status' => 'closed',
                'shift_end' => $start->copy()->addHours(8),
                'closing_photo_path' => fake()->filePath(),
                'scheduled_end' => $start->copy()->addHours(8),
            ];
        });
    }
}
