<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Task;
use App\Models\TaskSharedProof;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Фабрика для создания общих файлов групповых задач.
 *
 * Используется для групповых задач, когда менеджер загружает доказательства
 * за всех участников.
 *
 * @extends Factory<TaskSharedProof>
 */
class TaskSharedProofFactory extends Factory
{
    protected $model = TaskSharedProof::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $mimeTypes = [
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/png' => ['png'],
            'application/pdf' => ['pdf'],
        ];

        $mimeType = fake()->randomElement(array_keys($mimeTypes));
        $extensions = $mimeTypes[$mimeType];
        $extension = fake()->randomElement($extensions);

        $fileSizes = [
            'image/jpeg' => [100000, 5000000],
            'image/png' => [50000, 3000000],
            'application/pdf' => [100000, 15000000],
        ];

        [$minSize, $maxSize] = $fileSizes[$mimeType];

        return [
            'task_id' => Task::factory(),
            'file_path' => 'task_shared_proofs/demo/group_' . Str::uuid() . '.' . $extension,
            'original_filename' => $this->generateFilename($mimeType, $extension),
            'mime_type' => $mimeType,
            'file_size' => fake()->numberBetween($minSize, $maxSize),
        ];
    }

    /**
     * Генерация реалистичного имени файла.
     */
    private function generateFilename(string $mimeType, string $extension): string
    {
        $prefixes = match ($mimeType) {
            'image/jpeg', 'image/png' => ['групповое_фото', 'общий_снимок', 'результат_работы'],
            'application/pdf' => ['отчёт_группы', 'акт_выполнения', 'протокол'],
            default => ['файл'],
        };

        $prefix = fake()->randomElement($prefixes);
        $timestamp = fake()->dateTimeBetween('-30 days', 'now')->format('Ymd');

        return "{$prefix}_{$timestamp}." . $extension;
    }

    /**
     * Создать изображение JPEG.
     */
    public function image(): static
    {
        return $this->state(fn (array $attributes) => [
            'file_path' => 'task_shared_proofs/demo/group_' . Str::uuid() . '.jpg',
            'original_filename' => 'групповое_фото_' . fake()->dateTimeBetween('-30 days', 'now')->format('Ymd') . '.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => fake()->numberBetween(100000, 5000000),
        ]);
    }

    /**
     * Создать PDF документ.
     */
    public function pdf(): static
    {
        return $this->state(fn (array $attributes) => [
            'file_path' => 'task_shared_proofs/demo/group_' . Str::uuid() . '.pdf',
            'original_filename' => 'отчёт_группы_' . fake()->dateTimeBetween('-30 days', 'now')->format('Ymd') . '.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => fake()->numberBetween(100000, 15000000),
        ]);
    }

    /**
     * Для конкретной задачи.
     */
    public function forTask(Task $task): static
    {
        return $this->state(fn (array $attributes) => [
            'task_id' => $task->id,
        ]);
    }
}
