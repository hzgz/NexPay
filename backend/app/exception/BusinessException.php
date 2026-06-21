<?php

namespace app\exception;

use RuntimeException;

class BusinessException extends RuntimeException
{
    public function __construct(
        string $message,
        protected int $errorCode = 1000,
        protected array $payload = [],
    ) {
        parent::__construct($message, $errorCode);
    }

    public function errorCode(): int
    {
        return $this->errorCode;
    }

    public function payload(): array
    {
        return $this->payload;
    }
}
