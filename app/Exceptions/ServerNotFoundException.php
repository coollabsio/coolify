<?php

namespace App\Exceptions;

class ServerNotFoundException extends ResourcePlacementException
{
    public function __construct(string $uuid)
    {
        parent::__construct("Server [{$uuid}] was not found in this team.");
    }
}
