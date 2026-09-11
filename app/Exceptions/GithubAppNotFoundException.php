<?php

namespace App\Exceptions;

class GithubAppNotFoundException extends ResourceCreationException
{
    public function __construct()
    {
        parent::__construct('Github App not found.');
    }
}
