<?php

namespace App\Exceptions;

class PrivateKeyNotFoundException extends ResourceCreationException
{
    public function __construct()
    {
        parent::__construct('Private Key not found.');
    }
}
