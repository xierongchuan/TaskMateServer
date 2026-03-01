<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * Исключение при попытке пользователя изменить запрещённые поля своего аккаунта.
 */
class SelfEditRestrictedException extends Exception
{
    /**
     * HTTP код ответа.
     */
    public function getHttpCode(): int
    {
        return 403;
    }
}
