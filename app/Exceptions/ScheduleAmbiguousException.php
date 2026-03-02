<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Illuminate\Support\Collection;

class ScheduleAmbiguousException extends Exception
{
    public function __construct(
        string $message,
        private readonly Collection $candidates
    ) {
        parent::__construct($message, 409);
    }

    public function getCandidates(): Collection
    {
        return $this->candidates;
    }
}
