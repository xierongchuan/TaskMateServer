<?php

declare(strict_types=1);

/**
 * Конфигурация загрузки файлов.
 *
 * Единый источник истины для всех типов файлов, разрешённых в системе.
 * Используется FileValidationConfig для доступа к этим настройкам.
 */
return [
    /*
    |--------------------------------------------------------------------------
    | Категории файлов
    |--------------------------------------------------------------------------
    |
    | Каждая категория содержит разрешённые расширения, MIME-типы и
    | максимальный размер файла в байтах.
    |
    */
    'categories' => [
        'image' => [
            'extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
            'mime_types' => [
                'image/jpeg',
                'image/png',
                'image/gif',
                'image/webp',
            ],
            'max_size' => 5 * 1024 * 1024, // 5 MB
        ],

        'document' => [
            'extensions' => [
                'pdf',
                'doc', 'docx',
                'xls', 'xlsx',
                'csv',
                'odt', 'ods', 'odp', 'odf', 'odg', // OpenDocument
                'txt',
                'json',
            ],
            'mime_types' => [
                // PDF
                'application/pdf',
                // MS Office (старые форматы)
                'application/msword',
                'application/vnd.ms-excel',
                // MS Office (OpenXML)
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                // OpenDocument (все форматы)
                'application/vnd.oasis.opendocument.text',         // odt
                'application/vnd.oasis.opendocument.spreadsheet',  // ods
                'application/vnd.oasis.opendocument.presentation', // odp
                'application/vnd.oasis.opendocument.formula',      // odf
                'application/vnd.oasis.opendocument.graphics',     // odg
                // Текстовые
                'text/csv',
                'text/plain',
                'text/rtf',
                'application/json',
            ],
            'max_size' => 50 * 1024 * 1024, // 50 MB
        ],

        'archive' => [
            'extensions' => ['zip', 'tar', '7z'],
            'mime_types' => [
                'application/zip',
                'application/x-tar',
                'application/x-7z-compressed',
                'application/x-compressed',
                'application/octet-stream', // Для некоторых архивов
            ],
            'max_size' => 50 * 1024 * 1024, // 50 MB
        ],

        'video' => [
            'extensions' => ['mp4', 'webm', 'mov', 'avi'],
            'mime_types' => [
                'video/mp4',
                'video/webm',
                'video/quicktime',
                'video/x-msvideo',
            ],
            'max_size' => 100 * 1024 * 1024, // 100 MB
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Глобальные лимиты
    |--------------------------------------------------------------------------
    */
    'limits' => [
        'max_files_per_response' => 5,
        'max_total_size' => 200 * 1024 * 1024, // 200 MB
    ],

    /*
    |--------------------------------------------------------------------------
    | Пресеты (наборы категорий для разных контекстов)
    |--------------------------------------------------------------------------
    |
    | task_proof — доказательства выполнения задач (все типы)
    | shift_photo — фото открытия/закрытия смены (только изображения)
    |
    */
    'presets' => [
        'task_proof' => ['image', 'document', 'archive', 'video'],
        'shift_photo' => ['image'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Маппинг расширений к MIME-типам
    |--------------------------------------------------------------------------
    |
    | Используется для корректировки MIME-типа Office документов,
    | которые определяются как application/zip.
    |
    */
    'extension_to_mime' => [
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'doc' => 'application/msword',
        'xls' => 'application/vnd.ms-excel',
        'odt' => 'application/vnd.oasis.opendocument.text',
        'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
        'odp' => 'application/vnd.oasis.opendocument.presentation',
        'odf' => 'application/vnd.oasis.opendocument.formula',
        'odg' => 'application/vnd.oasis.opendocument.graphics',
    ],
];
