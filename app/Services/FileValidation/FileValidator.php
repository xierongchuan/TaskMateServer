<?php

declare(strict_types=1);

namespace App\Services\FileValidation;

use App\Contracts\FileValidatorInterface;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

/**
 * Валидатор файлов.
 *
 * Проверяет загружаемые файлы на соответствие разрешённым типам,
 * размерам и содержимому (magic bytes).
 *
 * Single Responsibility: только валидация файлов.
 */
class FileValidator implements FileValidatorInterface
{
    public function __construct(
        private readonly FileValidationConfig $config,
        private readonly MimeTypeResolver $mimeResolver
    ) {}

    /**
     * {@inheritdoc}
     */
    public function validate(UploadedFile $file, string $preset = 'task_proof'): void
    {
        // Проверка расширения
        $extension = strtolower($file->getClientOriginalExtension());

        if (!$this->isAllowedExtension($extension, $preset)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Недопустимое расширение файла "%s". Разрешены: %s',
                    $extension,
                    implode(', ', $this->config->getAllowedExtensions($preset))
                )
            );
        }

        // Получить правильный MIME (с коррекцией для Office документов)
        $mimeType = $this->mimeResolver->resolve($file);

        // Проверить MIME-тип
        if (!$this->isAllowedMimeType($mimeType, $preset)) {
            throw new InvalidArgumentException(
                sprintf('Недопустимый тип файла: %s', $mimeType)
            );
        }

        // Проверка реального содержимого файла
        $this->validateContent($file, $extension, $mimeType);

        // Проверка размера в зависимости от типа
        $fileSize = $file->getSize();
        $maxSize = $this->getMaxSizeForMimeType($mimeType);

        if ($fileSize > $maxSize) {
            throw new InvalidArgumentException(
                sprintf(
                    'Файл слишком большой (%d MB). Максимальный размер для этого типа: %d MB',
                    round($fileSize / 1024 / 1024, 1),
                    $maxSize / 1024 / 1024
                )
            );
        }
    }

    /**
     * Валидировать MIME-тип напрямую (для Job'ов где файл уже сохранён).
     *
     * @param string $mimeType MIME-тип файла
     * @param string $preset Пресет
     * @throws InvalidArgumentException Если MIME-тип не разрешён
     */
    public function validateMimeType(string $mimeType, string $preset = 'task_proof'): void
    {
        if (!$this->isAllowedMimeType($mimeType, $preset)) {
            throw new InvalidArgumentException(
                sprintf('Недопустимый тип файла: %s', $mimeType)
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isAllowedExtension(string $extension, string $preset = 'task_proof'): bool
    {
        $allowedExtensions = $this->config->getAllowedExtensions($preset);

        return in_array(strtolower($extension), $allowedExtensions, true);
    }

    /**
     * {@inheritdoc}
     */
    public function isAllowedMimeType(string $mimeType, string $preset = 'task_proof'): bool
    {
        $allowedMimeTypes = $this->config->getAllowedMimeTypes($preset);

        return in_array($mimeType, $allowedMimeTypes, true);
    }

    /**
     * {@inheritdoc}
     */
    public function getMaxSizeForMimeType(string $mimeType): int
    {
        $category = $this->mimeResolver->getCategoryForMime($mimeType);

        if ($category === null) {
            return $this->config->getMaxSize(FileTypeCategory::DOCUMENT);
        }

        return $this->config->getMaxSize($category);
    }

    /**
     * {@inheritdoc}
     */
    public function getCategoryForMimeType(string $mimeType): ?FileTypeCategory
    {
        return $this->mimeResolver->getCategoryForMime($mimeType);
    }

    /**
     * {@inheritdoc}
     */
    public function getAllowedExtensions(string $preset = 'task_proof'): array
    {
        return $this->config->getAllowedExtensions($preset);
    }

    /**
     * {@inheritdoc}
     */
    public function getAllowedMimeTypes(string $preset = 'task_proof'): array
    {
        return $this->config->getAllowedMimeTypes($preset);
    }

    /**
     * {@inheritdoc}
     */
    public function resolveMimeType(UploadedFile $file): string
    {
        return $this->mimeResolver->resolve($file);
    }

    /**
     * Получить конфигурацию валидации.
     *
     * @return FileValidationConfig
     */
    public function getConfig(): FileValidationConfig
    {
        return $this->config;
    }

    /**
     * Получить резолвер MIME-типов.
     *
     * @return MimeTypeResolver
     */
    public function getMimeResolver(): MimeTypeResolver
    {
        return $this->mimeResolver;
    }

    /**
     * Проверка реального содержимого файла (magic bytes).
     *
     * Защита от загрузки файлов с подменённым расширением.
     *
     * @param UploadedFile $file Загружаемый файл
     * @param string $extension Расширение файла
     * @param string $mimeType MIME-тип файла
     * @throws InvalidArgumentException
     */
    private function validateContent(UploadedFile $file, string $extension, string $mimeType): void
    {
        $filePath = $file->getPathname();

        // Проверка изображений через getimagesize
        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (in_array($extension, $imageExtensions, true)) {
            $this->validateImageContent($filePath, $extension);
        }

        // Проверка PDF через magic bytes
        if ($extension === 'pdf') {
            $this->validatePdfContent($filePath);
        }

        // Проверка ZIP-архивов через magic bytes
        if ($extension === 'zip') {
            $this->validateZipContent($filePath);
        }

        // Проверка видео через finfo
        $videoExtensions = ['mp4', 'webm', 'mov', 'avi'];
        if (in_array($extension, $videoExtensions, true)) {
            $this->validateVideoContent($filePath);
        }
    }

    /**
     * Валидация изображения через getimagesize.
     */
    private function validateImageContent(string $filePath, string $extension): void
    {
        $imageInfo = @getimagesize($filePath);

        if ($imageInfo === false) {
            throw new InvalidArgumentException(
                'Файл не является допустимым изображением'
            );
        }

        // Проверяем соответствие типа изображения расширению
        $imageTypeMap = [
            IMAGETYPE_JPEG => ['jpg', 'jpeg'],
            IMAGETYPE_PNG => ['png'],
            IMAGETYPE_GIF => ['gif'],
            IMAGETYPE_WEBP => ['webp'],
        ];

        $detectedType = $imageInfo[2];
        $allowedExtensions = $imageTypeMap[$detectedType] ?? [];

        if (!in_array($extension, $allowedExtensions, true)) {
            throw new InvalidArgumentException(
                'Расширение файла не соответствует реальному типу изображения'
            );
        }
    }

    /**
     * Валидация PDF через magic bytes.
     */
    private function validatePdfContent(string $filePath): void
    {
        $handle = fopen($filePath, 'rb');
        if ($handle) {
            $header = fread($handle, 4);
            fclose($handle);

            if ($header !== '%PDF') {
                throw new InvalidArgumentException(
                    'Файл не является допустимым PDF-документом'
                );
            }
        }
    }

    /**
     * Валидация ZIP через magic bytes.
     */
    private function validateZipContent(string $filePath): void
    {
        $handle = fopen($filePath, 'rb');
        if ($handle) {
            $header = fread($handle, 4);
            fclose($handle);

            // ZIP magic bytes: PK\x03\x04 или PK\x05\x06 (пустой архив)
            if (substr($header, 0, 2) !== 'PK') {
                throw new InvalidArgumentException(
                    'Файл не является допустимым ZIP-архивом'
                );
            }
        }
    }

    /**
     * Валидация видео через finfo.
     */
    private function validateVideoContent(string $filePath): void
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->file($filePath);

        $videoMimes = [
            'video/mp4',
            'video/webm',
            'video/quicktime',
            'video/x-msvideo',
            'application/octet-stream', // Некоторые видео определяются так
        ];

        if (!in_array($detectedMime, $videoMimes, true)) {
            throw new InvalidArgumentException(
                sprintf('Файл не является допустимым видео (обнаружен тип: %s)', $detectedMime)
            );
        }
    }
}
