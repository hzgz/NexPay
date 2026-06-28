<?php

namespace app\exception;

class SecurityException extends BusinessException
{
    public function __construct(
        string $message,
        protected int $httpStatus = 403,
        int $errorCode = 1000,
        array $payload = [],
    ) {
        parent::__construct($message, $errorCode, $payload);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}
