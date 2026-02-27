<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ExpenseRequest;
use App\Models\User;
use App\Enums\ExpenseStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ExpenseRequest>
 */
class ExpenseRequestFactory extends Factory
{
    protected $model = ExpenseRequest::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'requester_id' => User::factory(),
            'title' => fake()->sentence(3),
            'description' => fake()->sentence(10),
            'amount' => fake()->randomFloat(2, 10, 10000),
            'currency' => fake()->randomElement(['UZS', 'USD', 'EUR']),
            'status' => fake()->randomElement([
                ExpenseStatus::PENDING->value,
                ExpenseStatus::APPROVED->value,
                ExpenseStatus::DECLINED->value,
                ExpenseStatus::ISSUED->value,
            ]),
            'company_id' => fake()->numberBetween(1, 10),
        ];
    }

    /**
     * Indicate that the expense request should have a specific status.
     */
    public function status(ExpenseStatus $status): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => $status->value,
        ]);
    }

    /**
     * Indicate that the expense request should belong to a specific company.
     */
    public function company(int $companyId): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $companyId,
        ]);
    }

    /**
     * Indicate that the expense request should have a specific amount.
     */
    public function amount(float $amount): static
    {
        return $this->state(fn (array $attributes) => [
            'amount' => $amount,
        ]);
    }

    /**
     * Indicate that the expense request should have a specific currency.
     */
    public function currency(string $currency): static
    {
        return $this->state(fn (array $attributes) => [
            'currency' => $currency,
        ]);
    }

    /**
     * Indicate that the expense request should be pending.
     */
    public function pending(): static
    {
        return $this->status(ExpenseStatus::PENDING);
    }

    /**
     * Indicate that the expense request should be approved.
     */
    public function approved(): static
    {
        return $this->status(ExpenseStatus::APPROVED);
    }

    /**
     * Indicate that the expense request should be declined.
     */
    public function declined(): static
    {
        return $this->status(ExpenseStatus::DECLINED);
    }

    /**
     * Indicate that the expense request should be issued.
     */
    public function issued(): static
    {
        return $this->status(ExpenseStatus::ISSUED);
    }
}
