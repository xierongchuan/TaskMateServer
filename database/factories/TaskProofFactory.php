<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\TaskProof;
use App\Models\TaskResponse;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Фабрика для создания демо-записей TaskProof.
 *
 * Создаёт записи без реальных файлов - только метаданные для демонстрации.
 *
 * @extends Factory<TaskProof>
 */
class TaskProofFactory extends Factory
{
    protected $model = TaskProof::class;

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
            'video/mp4' => ['mp4'],
            'application/pdf' => ['pdf'],
        ];

        $mimeType = fake()->randomElement(array_keys($mimeTypes));
        $extensions = $mimeTypes[$mimeType];
        $extension = fake()->randomElement($extensions);

        $fileSizes = [
            'image/jpeg' => [100000, 5000000],     // 100KB - 5MB
            'image/png' => [50000, 3000000],       // 50KB - 3MB
            'video/mp4' => [1000000, 100000000],   // 1MB - 100MB
            'application/pdf' => [50000, 10000000], // 50KB - 10MB
        ];

        [$minSize, $maxSize] = $fileSizes[$mimeType];

        return [
            'task_response_id' => TaskResponse::factory(),
            'file_path' => 'task_proofs/demo/stub_' . Str::uuid() . '.' . $extension,
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
            'image/jpeg', 'image/png' => ['фото', 'снимок', 'изображение', 'IMG'],
            'video/mp4' => ['видео', 'запись', 'VID'],
            'application/pdf' => ['документ', 'отчёт', 'акт', 'DOC'],
            default => ['файл'],
        };

        $prefix = fake()->randomElement($prefixes);
        $timestamp = fake()->dateTimeBetween('-30 days', 'now')->format('Ymd_His');

        return "{$prefix}_{$timestamp}." . $extension;
    }

    /**
     * Создать изображение JPEG.
     */
    public function image(): static
    {
        return $this->state(fn (array $attributes) => [
            'file_path' => 'task_proofs/demo/stub_' . Str::uuid() . '.jpg',
            'original_filename' => 'фото_' . fake()->dateTimeBetween('-30 days', 'now')->format('Ymd_His') . '.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => fake()->numberBetween(100000, 5000000),
        ]);
    }

    /**
     * Создать изображение PNG.
     */
    public function png(): static
    {
        return $this->state(fn (array $attributes) => [
            'file_path' => 'task_proofs/demo/stub_' . Str::uuid() . '.png',
            'original_filename' => 'скриншот_' . fake()->dateTimeBetween('-30 days', 'now')->format('Ymd_His') . '.png',
            'mime_type' => 'image/png',
            'file_size' => fake()->numberBetween(50000, 3000000),
        ]);
    }

    /**
     * Создать видео MP4.
     */
    public function video(): static
    {
        return $this->state(fn (array $attributes) => [
            'file_path' => 'task_proofs/demo/stub_' . Str::uuid() . '.mp4',
            'original_filename' => 'видео_' . fake()->dateTimeBetween('-30 days', 'now')->format('Ymd_His') . '.mp4',
            'mime_type' => 'video/mp4',
            'file_size' => fake()->numberBetween(1000000, 100000000),
        ]);
    }

    /**
     * Создать PDF документ.
     */
    public function pdf(): static
    {
        return $this->state(fn (array $attributes) => [
            'file_path' => 'task_proofs/demo/stub_' . Str::uuid() . '.pdf',
            'original_filename' => 'отчёт_' . fake()->dateTimeBetween('-30 days', 'now')->format('Ymd_His') . '.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => fake()->numberBetween(50000, 10000000),
        ]);
    }

    /**
     * Создать для конкретного ответа на задачу.
     */
    public function forResponse(TaskResponse $response): static
    {
        return $this->state(fn (array $attributes) => [
            'task_response_id' => $response->id,
        ]);
    }
}
