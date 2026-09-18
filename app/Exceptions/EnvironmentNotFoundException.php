<?php

namespace App\Exceptions;

class EnvironmentNotFoundException extends ResourcePlacementException
{
    public function __construct()
    {
        parent::__construct('Environment not found; provide a valid environment_name or environment_uuid.');
    }
}
