<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;

/**
 * Модель доказательства выполнения задачи.
 *
 * Файлы хранятся в приватном хранилище и доступны только через
 * подписанные URL с ограниченным временем действия.
 *
 * @property int $id
 * @property int $task_response_id
 * @property string $file_path
 * @property string $original_filename
 * @property string $mime_type
 * @property int $file_size
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read string $url
 * @property-read TaskResponse $taskResponse
 */
class TaskProof extends Model
{
    use HasFactory;

    protected $table = 'task_proofs';

    protected $fillable = [
        'task_response_id',
        'file_path',
        'original_filename',
        'mime_type',
        'file_size',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'task_response_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Время жизни подписанного URL (в минутах).
     */
    private const SIGNED_URL_EXPIRATION_MINUTES = 60;

    /**
     * Связь с ответом на задачу.
     */
    public function taskResponse(): BelongsTo
    {
        return $this->belongsTo(TaskResponse::class, 'task_response_id');
    }

    /**
     * Получить подписанный URL для скачивания файла.
     *
     * URL действителен ограниченное время и требует авторизации.
     */
    public function getUrlAttribute(): string
    {
        return URL::temporarySignedRoute(
            'task-proofs.download',
            now()->addMinutes(self::SIGNED_URL_EXPIRATION_MINUTES),
            ['id' => $this->id]
        );
    }

    /**
     * Преобразование в массив для API.
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'file_size' => $this->file_size,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
