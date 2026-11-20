<?php

namespace App\Exceptions;

class ServerCannotHostResourcesException extends ResourcePlacementException
{
    public function __construct()
    {
        parent::__construct('That server cannot host resources (build-only or unavailable).');
    }
}
