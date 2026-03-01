<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\FileValidation\FileValidationConfig;
use App\Traits\ApiResponses;
use Illuminate\Http\JsonResponse;

/**
 * Контроллер для получения конфигурации загрузки файлов.
 *
 * Предоставляет API endpoint для frontend, чтобы он мог
 * получить актуальные ограничения на загрузку файлов.
 */
class FileConfigController extends Controller
{
    use ApiResponses;

    /**
     * Получить конфигурацию загрузки файлов.
     */
    public function index(FileValidationConfig $config): JsonResponse
    {
        return $this->successResponse([
            'task_proof' => $config->toArray('task_proof'),
            'shift_photo' => $config->toArray('shift_photo'),
        ]);
    }

    /**
     * Получить конфигурацию для конкретного пресета.
     */
    public function show(FileValidationConfig $config, string $preset): JsonResponse
    {
        if (! $config->presetExists($preset)) {
            return $this->errorResponse('Неизвестный пресет', 404);
        }

        return $this->successResponse($config->toArray($preset));
    }
}
