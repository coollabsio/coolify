<?php

namespace App\Exceptions;

class EnvironmentAlreadyExistsException extends ResourceCreationException
{
    public function __construct()
    {
        parent::__construct('Environment with this name already exists.');
    }
}
