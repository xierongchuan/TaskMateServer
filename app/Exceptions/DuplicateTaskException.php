<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * Исключение при попытке создания дубликата задачи.
 */
class DuplicateTaskException extends Exception
{
    /**
     * HTTP код ответа.
     */
    public function getHttpCode(): int
    {
        return 422;
    }
}
