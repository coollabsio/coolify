<?php

namespace App\Ai\Exceptions;

use Exception;

class AssistantBusyException extends Exception
{
    public function __construct(string $message = 'This conversation is already responding. Please wait for the current turn to finish.')
    {
        parent::__construct($message);
    }
}
