<?php

namespace App\Exceptions;

use Exception;

/**
 * Expected, user-facing reason why a database could not be started
 * (for example a missing prerequisite). The message is shown in the start log.
 */
class DatabaseStartException extends Exception
{
    public static function missingCaCertificate(): static
    {
        return new static('No CA certificate found for this database. Please generate a CA certificate for this server in the server/advanced page.');
    }

    public static function startCommandsDidNotRun(): static
    {
        return new static('Database start ended without running the start commands.');
    }
}
