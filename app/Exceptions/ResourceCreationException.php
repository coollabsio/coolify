<?php

namespace App\Exceptions;

use Exception;

class ResourceCreationException extends Exception
{
    /**
     * @param  array<string, mixed>  $payload  Extra keys surfaces may expose (e.g. conflicts).
     */
    public function __construct(string $message, public readonly array $payload = [])
    {
        parent::__construct($message);
    }
}
