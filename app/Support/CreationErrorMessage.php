<?php

namespace App\Support;

use App\Exceptions\ResourceCreationException;
use App\Exceptions\ResourcePlacementException;
use Throwable;

/**
 * Turn a resource-creation failure into a message that is safe to return to an
 * MCP client or the AI assistant. Expected domain/validation errors keep their
 * text; anything else (database, filesystem, ...) is reported server-side and
 * replaced with a generic message so internals never leak into tool results.
 */
class CreationErrorMessage
{
    public static function for(Throwable $e, string $fallback): string
    {
        $isExpected = $e instanceof ResourcePlacementException
            || $e instanceof ResourceCreationException
            || $e instanceof \InvalidArgumentException
            || ($e instanceof \RuntimeException && ! $e instanceof \PDOException);

        if ($isExpected) {
            return $e->getMessage();
        }

        report($e);

        return $fallback;
    }
}
