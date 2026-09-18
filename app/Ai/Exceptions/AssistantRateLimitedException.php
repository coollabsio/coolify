<?php

namespace App\Ai\Exceptions;

use Exception;

class AssistantRateLimitedException extends Exception
{
    public function __construct(string $message = 'Too many assistant requests. Please slow down.')
    {
        parent::__construct($message);
    }
}
