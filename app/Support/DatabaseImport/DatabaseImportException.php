<?php

namespace App\Support\DatabaseImport;

use RuntimeException;

class DatabaseImportException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
