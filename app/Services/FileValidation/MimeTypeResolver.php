<?php

declare(strict_types=1);

namespace App\Services\FileValidation;

use Illuminate\Http\UploadedFile;

/**
 * Резолвер MIME-типов файлов.
 *
 * Определяет реальный MIME-тип файла и его категорию.
 * Single Responsibility: только работа с MIME-типами.
 */
class MimeTypeResolver
{
    public function __construct(
        private readonly FileValidationConfig $config
    ) {}

    /**
     * Определить MIME-тип файла с корректировкой для Office документов.
     *
     * Office документы (.docx, .xlsx) являются ZIP-архивами,
     * поэтому getMimeType() может вернуть application/zip.
     * Эта функция исправляет MIME тип на основе расширения.
     *
     * @param UploadedFile $file Загружаемый файл
     * @return string Правильный MIME-тип
     */
    public function resolve(UploadedFile $file): string
    {
        $detectedMime = $file->getMimeType() ?? 'application/octet-stream';
        $extension = strtolower($file->getClientOriginalExtension());

        $extensionToMime = $this->config->getExtensionToMimeMap();

        // Если обнаружен ZIP, но расширение указывает на Office документ
        if ($detectedMime === 'application/zip' && isset($extensionToMime[$extension])) {
            return $extensionToMime[$extension];
        }

        // Если MIME тип не определён, но расширение известно
        if ($detectedMime === 'application/octet-stream' && isset($extensionToMime[$extension])) {
            return $extensionToMime[$extension];
        }

        return $detectedMime;
    }

    /**
     * Определить категорию файла по MIME-типу.
     *
     * @param string $mimeType MIME-тип файла
     * @return FileTypeCategory|null Категория или null если не найдена
     */
    public function getCategoryForMime(string $mimeType): ?FileTypeCategory
    {
        if (str_starts_with($mimeType, 'image/')) {
            return FileTypeCategory::IMAGE;
        }

        if (str_starts_with($mimeType, 'video/')) {
            return FileTypeCategory::VIDEO;
        }

        // Проверяем архивы
        $archiveMimes = [
            'application/zip',
            'application/x-tar',
            'application/x-7z-compressed',
            'application/x-compressed',
        ];

        if (in_array($mimeType, $archiveMimes, true)) {
            return FileTypeCategory::ARCHIVE;
        }

        // Всё остальное - документы
        $documentMimes = $this->config->getCategoryConfig(FileTypeCategory::DOCUMENT)['mime_types'];

        if (in_array($mimeType, $documentMimes, true)) {
            return FileTypeCategory::DOCUMENT;
        }

        // application/octet-stream может быть архивом (7z) или документом
        if ($mimeType === 'application/octet-stream') {
            return FileTypeCategory::ARCHIVE;
        }

        return null;
    }

    /**
     * Определить категорию файла по расширению.
     *
     * @param string $extension Расширение файла (без точки)
     * @return FileTypeCategory|null Категория или null если не найдена
     */
    public function getCategoryForExtension(string $extension): ?FileTypeCategory
    {
        $extension = strtolower($extension);

        foreach (FileTypeCategory::cases() as $category) {
            $categoryConfig = $this->config->getCategoryConfig($category);
            if (in_array($extension, $categoryConfig['extensions'], true)) {
                return $category;
            }
        }

        return null;
    }
}
