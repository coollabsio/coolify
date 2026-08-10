<?php

namespace App\Exceptions;

use Exception;

class RateLimitException extends Exception
{
    public function __construct(
        string $message = 'Rate limit exceeded.',
        public readonly ?int $retryAfter = null
    ) {
        parent::__construct($message);
    }
}
