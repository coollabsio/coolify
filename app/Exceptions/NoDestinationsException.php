<?php

namespace App\Exceptions;

class NoDestinationsException extends ResourcePlacementException
{
    public function __construct()
    {
        parent::__construct('Server has no destinations.');
    }
}
