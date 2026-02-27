<?php

declare(strict_types=1);

namespace App\Services\FileValidation;

use Illuminate\Config\Repository;
use InvalidArgumentException;

/**
 * Класс для работы с конфигурацией загрузки файлов.
 *
 * Предоставляет типизированный доступ к настройкам из config/file_upload.php.
 * Single Responsibility: только чтение и преобразование конфигурации.
 */
class FileValidationConfig
{
    /**
     * @var array<string, mixed> Кэшированная конфигурация
     */
    private array $config;

    public function __construct(Repository $configRepository)
    {
        $this->config = $configRepository->get('file_upload', []);
    }

    /**
     * Получить список разрешённых расширений для пресета.
     *
     * @param string $preset Пресет ('task_proof', 'shift_photo')
     * @return array<string>
     * @throws InvalidArgumentException Если пресет не найден
     */
    public function getAllowedExtensions(string $preset = 'task_proof'): array
    {
        $categories = $this->getCategoriesForPreset($preset);
        $extensions = [];

        foreach ($categories as $categoryName) {
            $categoryConfig = $this->config['categories'][$categoryName] ?? [];
            $extensions = array_merge($extensions, $categoryConfig['extensions'] ?? []);
        }

        return array_unique($extensions);
    }

    /**
     * Получить список разрешённых MIME-типов для пресета.
     *
     * @param string $preset Пресет
     * @return array<string>
     * @throws InvalidArgumentException Если пресет не найден
     */
    public function getAllowedMimeTypes(string $preset = 'task_proof'): array
    {
        $categories = $this->getCategoriesForPreset($preset);
        $mimeTypes = [];

        foreach ($categories as $categoryName) {
            $categoryConfig = $this->config['categories'][$categoryName] ?? [];
            $mimeTypes = array_merge($mimeTypes, $categoryConfig['mime_types'] ?? []);
        }

        return array_unique($mimeTypes);
    }

    /**
     * Получить максимальный размер файла для категории.
     *
     * @param FileTypeCategory $category Категория
     * @return int Размер в байтах
     */
    public function getMaxSize(FileTypeCategory $category): int
    {
        return $this->config['categories'][$category->value]['max_size']
            ?? 50 * 1024 * 1024; // 50 MB по умолчанию
    }

    /**
     * Получить глобальные лимиты.
     *
     * @return array{max_files_per_response: int, max_total_size: int}
     */
    public function getLimits(): array
    {
        return [
            'max_files_per_response' => $this->config['limits']['max_files_per_response'] ?? 5,
            'max_total_size' => $this->config['limits']['max_total_size'] ?? 200 * 1024 * 1024,
        ];
    }

    /**
     * Получить максимальное количество файлов на ответ.
     *
     * @return int
     */
    public function getMaxFilesPerResponse(): int
    {
        return $this->config['limits']['max_files_per_response'] ?? 5;
    }

    /**
     * Получить максимальный общий размер файлов.
     *
     * @return int Размер в байтах
     */
    public function getMaxTotalSize(): int
    {
        return $this->config['limits']['max_total_size'] ?? 200 * 1024 * 1024;
    }

    /**
     * Получить маппинг расширений к MIME-типам.
     *
     * @return array<string, string>
     */
    public function getExtensionToMimeMap(): array
    {
        return $this->config['extension_to_mime'] ?? [];
    }

    /**
     * Получить конфигурацию категории.
     *
     * @param FileTypeCategory $category Категория
     * @return array{extensions: array<string>, mime_types: array<string>, max_size: int}
     */
    public function getCategoryConfig(FileTypeCategory $category): array
    {
        return $this->config['categories'][$category->value] ?? [
            'extensions' => [],
            'mime_types' => [],
            'max_size' => 50 * 1024 * 1024,
        ];
    }

    /**
     * Получить все доступные пресеты.
     *
     * @return array<string, array<string>>
     */
    public function getPresets(): array
    {
        return $this->config['presets'] ?? [];
    }

    /**
     * Преобразовать конфигурацию в массив для API.
     *
     * @param string $preset Пресет
     * @return array{extensions: array<string>, mime_types: array<string>, limits: array{max_files: int, max_total_size: int, max_size_image: int, max_size_document: int, max_size_video: int}}
     */
    public function toArray(string $preset = 'task_proof'): array
    {
        return [
            'extensions' => $this->getAllowedExtensions($preset),
            'mime_types' => $this->getAllowedMimeTypes($preset),
            'limits' => [
                'max_files' => $this->getMaxFilesPerResponse(),
                'max_total_size' => $this->getMaxTotalSize(),
                'max_size_image' => $this->getMaxSize(FileTypeCategory::IMAGE),
                'max_size_document' => $this->getMaxSize(FileTypeCategory::DOCUMENT),
                'max_size_archive' => $this->getMaxSize(FileTypeCategory::ARCHIVE),
                'max_size_video' => $this->getMaxSize(FileTypeCategory::VIDEO),
            ],
        ];
    }

    /**
     * Проверить существование пресета.
     *
     * @param string $preset Название пресета
     * @return bool
     */
    public function presetExists(string $preset): bool
    {
        return isset($this->config['presets'][$preset]);
    }

    /**
     * Получить категории для пресета.
     *
     * @param string $preset Пресет
     * @return array<string>
     * @throws InvalidArgumentException Если пресет не найден
     */
    private function getCategoriesForPreset(string $preset): array
    {
        if (!$this->presetExists($preset)) {
            throw new InvalidArgumentException(
                sprintf('Неизвестный пресет "%s". Доступные: %s', $preset, implode(', ', array_keys($this->config['presets'] ?? [])))
            );
        }

        return $this->config['presets'][$preset];
    }
}
