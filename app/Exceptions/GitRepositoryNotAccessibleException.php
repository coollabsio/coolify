<?php

namespace App\Exceptions;

class GitRepositoryNotAccessibleException extends ResourceCreationException
{
    public function __construct(string $message = 'Repository not found or not accessible by the GitHub App.')
    {
        parent::__construct($message);
    }
}
