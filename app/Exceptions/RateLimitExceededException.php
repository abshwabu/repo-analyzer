<?php

namespace App\Exceptions;

use Exception;

class RateLimitExceededException extends Exception
{
    protected int $resetTimestamp;
    protected int $retryAfterSeconds;

    public function __construct(string $message, int $resetTimestamp, int $retryAfterSeconds, int $code = 429, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->resetTimestamp = $resetTimestamp;
        $this->retryAfterSeconds = $retryAfterSeconds;
    }

    public function getResetTimestamp(): int
    {
        return $this->resetTimestamp;
    }

    public function getRetryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }
}
