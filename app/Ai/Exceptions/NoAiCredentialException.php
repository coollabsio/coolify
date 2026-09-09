<?php

namespace App\Ai\Exceptions;

use Exception;

class NoAiCredentialException extends Exception
{
    public function __construct(string $message = 'No AI provider credential is configured for this team.')
    {
        parent::__construct($message);
    }
}
