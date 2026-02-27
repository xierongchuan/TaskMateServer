<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Services\FileValidation\FileTypeCategory;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

/**
 * Интерфейс валидатора файлов.
 *
 * Определяет контракт для валидации загружаемых файлов.
 * Позволяет использовать разные реализации (Dependency Inversion).
 */
interface FileValidatorInterface
{
    /**
     * Валидировать файл для указанного пресета.
     *
     * @param UploadedFile $file Загружаемый файл
     * @param string $preset Пресет ('task_proof', 'shift_photo')
     * @throws InvalidArgumentException Если файл не прошёл валидацию
     */
    public function validate(UploadedFile $file, string $preset = 'task_proof'): void;

    /**
     * Проверить, разрешено ли расширение файла.
     *
     * @param string $extension Расширение файла (без точки)
     * @param string $preset Пресет
     * @return bool
     */
    public function isAllowedExtension(string $extension, string $preset = 'task_proof'): bool;

    /**
     * Проверить, разрешён ли MIME-тип файла.
     *
     * @param string $mimeType MIME-тип
     * @param string $preset Пресет
     * @return bool
     */
    public function isAllowedMimeType(string $mimeType, string $preset = 'task_proof'): bool;

    /**
     * Получить максимальный размер файла по MIME-типу.
     *
     * @param string $mimeType MIME-тип файла
     * @return int Размер в байтах
     */
    public function getMaxSizeForMimeType(string $mimeType): int;

    /**
     * Получить категорию файла по MIME-типу.
     *
     * @param string $mimeType MIME-тип файла
     * @return FileTypeCategory|null Категория или null если не найдена
     */
    public function getCategoryForMimeType(string $mimeType): ?FileTypeCategory;

    /**
     * Получить список разрешённых расширений для пресета.
     *
     * @param string $preset Пресет
     * @return array<string>
     */
    public function getAllowedExtensions(string $preset = 'task_proof'): array;

    /**
     * Получить список разрешённых MIME-типов для пресета.
     *
     * @param string $preset Пресет
     * @return array<string>
     */
    public function getAllowedMimeTypes(string $preset = 'task_proof'): array;

    /**
     * Определить MIME-тип файла с корректировкой для Office документов.
     *
     * @param UploadedFile $file Загружаемый файл
     * @return string Правильный MIME-тип
     */
    public function resolveMimeType(UploadedFile $file): string;

    /**
     * Валидировать MIME-тип напрямую (для Job'ов где файл уже сохранён).
     *
     * @param string $mimeType MIME-тип файла
     * @param string $preset Пресет
     * @throws InvalidArgumentException Если MIME-тип не разрешён
     */
    public function validateMimeType(string $mimeType, string $preset = 'task_proof'): void;
}
