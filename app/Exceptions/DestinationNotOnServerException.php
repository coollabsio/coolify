<?php

namespace App\Exceptions;

class DestinationNotOnServerException extends ResourcePlacementException
{
    public function __construct()
    {
        parent::__construct('Provided destination_uuid does not belong to the specified server.');
    }
}
