<?php

namespace App\Exceptions;

class AmbiguousDestinationException extends ResourcePlacementException
{
    public function __construct()
    {
        parent::__construct('The server has multiple destinations; specify destination_uuid.');
    }
}
