<?php

namespace App\Ai\Exceptions;

use Exception;

class AssistantDisabledException extends Exception
{
    public function __construct(string $message = 'The AI assistant is disabled for this team.')
    {
        parent::__construct($message);
    }
}
