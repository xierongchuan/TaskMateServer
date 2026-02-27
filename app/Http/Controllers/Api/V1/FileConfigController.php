<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\FileValidation\FileValidationConfig;
use Illuminate\Http\JsonResponse;

/**
 * Контроллер для получения конфигурации загрузки файлов.
 *
 * Предоставляет API endpoint для frontend, чтобы он мог
 * получить актуальные ограничения на загрузку файлов.
 */
class FileConfigController extends Controller
{
    /**
     * Получить конфигурацию загрузки файлов.
     *
     * @param FileValidationConfig $config
     * @return JsonResponse
     */
    public function index(FileValidationConfig $config): JsonResponse
    {
        return response()->json([
            'task_proof' => $config->toArray('task_proof'),
            'shift_photo' => $config->toArray('shift_photo'),
        ]);
    }

    /**
     * Получить конфигурацию для конкретного пресета.
     *
     * @param FileValidationConfig $config
     * @param string $preset
     * @return JsonResponse
     */
    public function show(FileValidationConfig $config, string $preset): JsonResponse
    {
        if (!$config->presetExists($preset)) {
            return response()->json([
                'error' => 'Неизвестный пресет',
                'available_presets' => array_keys($config->getPresets()),
            ], 404);
        }

        return response()->json($config->toArray($preset));
    }
}
