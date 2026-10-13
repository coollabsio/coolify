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

    public static function clickhouseDataInAnonymousVolume(string $volumeName): static
    {
        return new static("This ClickHouse database keeps its current data in the unnamed Docker volume {$volumeName}, not in its data volume. A start or restart would use the data volume, and the current data would seem lost. On the database's General page, click \"Keep current data\" first.");
    }

    public static function startCommandsDidNotRun(): static
    {
        return new static('Database start ended without running the start commands.');
    }
}
