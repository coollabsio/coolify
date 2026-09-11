<?php

namespace App\Exceptions;

class ProjectNotFoundException extends ResourcePlacementException
{
    public function __construct(string $uuid)
    {
        parent::__construct("Project [{$uuid}] was not found in this team.");
    }
}
