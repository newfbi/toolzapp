<?php

namespace App\Services;

use RuntimeException;

class ComprarSmsApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        public readonly array $payload = [],
    ) {
        parent::__construct($message, $statusCode);
    }
}
