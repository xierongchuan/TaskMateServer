<?php

namespace Database\Factories;

use App\Models\AutoDealership;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\NotificationSetting>
 */
class NotificationSettingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'dealership_id' => AutoDealership::factory(),
            'channel_type' => fake()->word,
            'is_enabled' => true,
            'notification_time' => fake()->time(),
            'notification_day' => fake()->dayOfWeek,
            'notification_offset' => fake()->numberBetween(1, 60),
        ];
    }
}
