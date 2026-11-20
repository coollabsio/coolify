<?php

namespace App\Exceptions;

class DomainAlreadyInUseException extends ResourceCreationException
{
    /** @param array<int, mixed> $conflicts */
    public function __construct(array $conflicts, string $warning)
    {
        parent::__construct('Domain conflicts detected. Use force_domain_override=true to proceed.', [
            'conflicts' => $conflicts,
            'warning' => $warning,
        ]);
    }
}
