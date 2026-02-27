<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\TaskResponse;
use App\Models\TaskVerificationHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Фабрика для создания истории верификации задач.
 *
 * @extends Factory<TaskVerificationHistory>
 */
class TaskVerificationHistoryFactory extends Factory
{
    protected $model = TaskVerificationHistory::class;

    /**
     * Причины отклонения для демо-данных.
     */
    private const REJECTION_REASONS = [
        'Нечёткое изображение, пожалуйста переснимите',
        'На фото не видно выполненной работы',
        'Требуется фото с другого ракурса',
        'Файл повреждён, загрузите заново',
        'Недостаточно доказательств выполнения',
        'Необходимо показать результат работы',
    ];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'task_response_id' => TaskResponse::factory(),
            'action' => TaskVerificationHistory::ACTION_SUBMITTED,
            'performed_by' => User::factory(),
            'reason' => null,
            'previous_status' => 'pending',
            'new_status' => 'pending_review',
            'proof_count' => fake()->numberBetween(1, 3),
            'created_at' => now(),
        ];
    }

    /**
     * Отправка на проверку.
     */
    public function submitted(): static
    {
        return $this->state(fn (array $attributes) => [
            'action' => TaskVerificationHistory::ACTION_SUBMITTED,
            'reason' => null,
            'previous_status' => 'pending',
            'new_status' => 'pending_review',
        ]);
    }

    /**
     * Одобрение.
     */
    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'action' => TaskVerificationHistory::ACTION_APPROVED,
            'reason' => null,
            'previous_status' => 'pending_review',
            'new_status' => 'completed',
        ]);
    }

    /**
     * Отклонение с причиной.
     */
    public function rejected(?string $reason = null): static
    {
        return $this->state(fn (array $attributes) => [
            'action' => TaskVerificationHistory::ACTION_REJECTED,
            'reason' => $reason ?? fake()->randomElement(self::REJECTION_REASONS),
            'previous_status' => 'pending_review',
            'new_status' => 'rejected',
        ]);
    }

    /**
     * Повторная отправка после отклонения.
     */
    public function resubmitted(): static
    {
        return $this->state(fn (array $attributes) => [
            'action' => TaskVerificationHistory::ACTION_RESUBMITTED,
            'reason' => null,
            'previous_status' => 'rejected',
            'new_status' => 'pending_review',
        ]);
    }

    /**
     * Для конкретного ответа.
     */
    public function forResponse(TaskResponse $response): static
    {
        return $this->state(fn (array $attributes) => [
            'task_response_id' => $response->id,
        ]);
    }

    /**
     * Выполнено пользователем.
     */
    public function performedBy(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'performed_by' => $user->id,
        ]);
    }

    /**
     * С указанием времени.
     */
    public function at(\DateTimeInterface $dateTime): static
    {
        return $this->state(fn (array $attributes) => [
            'created_at' => $dateTime,
        ]);
    }

    /**
     * Получить случайную причину отклонения.
     */
    public static function randomRejectionReason(): string
    {
        return fake()->randomElement(self::REJECTION_REASONS);
    }
}
