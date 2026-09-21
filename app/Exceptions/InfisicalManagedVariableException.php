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

    /**
     * The bulk textarea deletes through a relation query builder, which fires
     * no model events, so the Eloquent hooks never see it. The surface has to
     * refuse the whole submit up front instead.
     */
    public static function forBulkEdit(): self
    {
        return new self(
            'Environment variables are managed by Infisical for this team. Edit them in Infisical instead — Coolify will pick the changes up on the next sync.'
        );
    }

    /**
     * ServerTransferImporter writes inside withoutEvents(), so the hooks are
     * bypassed entirely. A bulk import copies another instance's data rather
     * than generating Coolify's own, so it counts as a human edit.
     */
    public static function forBulkImport(): self
    {
        return new self(
            'This team is connected to Infisical, so its environment variables are managed there. Disable the Infisical connection before importing a server transfer bundle.'
        );
    }
}
