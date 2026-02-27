<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * Исключение при отказе в доступе.
 */
class AccessDeniedException extends Exception
{
    /**
     * HTTP код ответа.
     */
    public function getHttpCode(): int
    {
        return 403;
    }
}
