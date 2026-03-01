<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Исключение при недопустимом переходе статуса задачи.
 *
 * Бросается TaskStatusMachine::validateTransition() когда запрошенный
 * переход статуса не разрешён матрицей переходов.
 */
class InvalidStatusTransitionException extends RuntimeException
{
    public function __construct(
        private readonly ?string $fromStatus,
        private readonly string $toStatus,
    ) {
        $from = $fromStatus ?? 'null';
        parent::__construct("Недопустимый переход статуса: {$from} -> {$toStatus}");
    }

    public function getFromStatus(): ?string
    {
        return $this->fromStatus;
    }

    public function getToStatus(): string
    {
        return $this->toStatus;
    }

    /**
     * HTTP код ответа.
     */
    public function getHttpCode(): int
    {
        return 422;
    }
}
