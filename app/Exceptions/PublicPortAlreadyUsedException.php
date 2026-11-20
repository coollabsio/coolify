<?php

namespace App\Exceptions;

class PublicPortAlreadyUsedException extends ResourceCreationException
{
    public function __construct()
    {
        parent::__construct('Public port already used by another database.');
    }
}
