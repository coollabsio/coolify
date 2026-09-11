<?php

namespace App\Exceptions;

class InvalidDockerComposeDomainsException extends ResourceCreationException
{
    /** @param array<int, string> $errors */
    public function __construct(array $errors)
    {
        parent::__construct('Validation failed.', ['errors' => ['docker_compose_domains' => $errors]]);
    }
}
