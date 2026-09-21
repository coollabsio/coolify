<?php

namespace App\Exceptions;

use RuntimeException;

class InfisicalManagedVariableException extends RuntimeException
{
    public static function forKey(?string $key = null): self
    {
        $subject = $key === null ? 'This variable' : "\"{$key}\"";

        return new self(
            "{$subject} is managed by Infisical. Edit it in Infisical instead — Coolify will pick the change up on the next sync."
        );
    }
}
