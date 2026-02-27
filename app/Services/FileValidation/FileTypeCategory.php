<?php

declare(strict_types=1);

namespace App\Services\FileValidation;

/**
 * Категории типов файлов.
 *
 * Используется для определения ограничений размера
 * и группировки разрешённых форматов.
 */
enum FileTypeCategory: string
{
    case IMAGE = 'image';
    case DOCUMENT = 'document';
    case ARCHIVE = 'archive';
    case VIDEO = 'video';

    /**
     * Получить человекочитаемое название категории.
     */
    public function label(): string
    {
        return match ($this) {
            self::IMAGE => 'Изображение',
            self::DOCUMENT => 'Документ',
            self::ARCHIVE => 'Архив',
            self::VIDEO => 'Видео',
        };
    }
}
