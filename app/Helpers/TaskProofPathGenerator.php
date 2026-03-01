<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Генератор путей для файлов доказательств задач.
 *
 * Централизует паттерн пути: dealerships/{id}/tasks/{id}/{date}/{filename}
 * Используется в TaskProofService, StoreTaskProofsJob, StoreTaskSharedProofsJob.
 */
class TaskProofPathGenerator
{
    /**
     * Сгенерировать полный путь к файлу доказательства.
     *
     * @param  int  $dealershipId  ID автосалона
     * @param  int  $taskId  ID задачи
     * @param  string  $filename  Имя файла (включая расширение)
     * @return string Путь вида dealerships/{dealershipId}/tasks/{taskId}/YYYY/MM/DD/{filename}
     */
    public static function generatePath(int $dealershipId, int $taskId, string $filename): string
    {
        $date = date('Y/m/d');

        return sprintf(
            'dealerships/%d/tasks/%d/%s/%s',
            $dealershipId,
            $taskId,
            $date,
            $filename
        );
    }
}
