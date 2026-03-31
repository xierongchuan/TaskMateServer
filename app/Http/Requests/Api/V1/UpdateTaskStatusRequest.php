<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\TaskResponseStatus;
use App\Services\TaskProofService;

/**
 * Form Request для обновления статуса задачи.
 */
class UpdateTaskStatusRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true; // auth:sanctum middleware
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => 'required|string|in:'.implode(',', TaskResponseStatus::allowedForUpdateStatus()),
            'complete_for_all' => 'sometimes|boolean',
            'proof_files' => 'sometimes|array|max:'.TaskProofService::MAX_FILES_PER_RESPONSE,
            'proof_files.*' => 'file|max:102400', // 100 MB max per file
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.required' => 'Статус обязателен',
            'status.in' => 'Некорректный статус',
            'proof_files.max' => 'Максимальное количество файлов: '.TaskProofService::MAX_FILES_PER_RESPONSE,
            'proof_files.*.max' => 'Размер файла не может превышать 100 МБ',
            'proof_files.*.file' => 'Загруженный объект должен быть файлом',
        ];
    }
}
