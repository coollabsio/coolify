<?php

namespace App\Support;

/**
 * Formats values fetched from a secret manager so that Docker Compose uses them exactly as they are:
 * no interpolation, no comments, and no extra lines.
 */
class RemoteSecretValueFormatter
{
    /**
     * A value for a dotenv file that Docker Compose reads (a project .env or an env_file). Single
     * quotes keep the value literal, also newlines and "#". A value with a single quote, or one that
     * ends with a backslash (which would escape the closing quote), uses double quotes instead,
     * where \, " and $ must be escaped ($$ stops interpolation).
     */
    public static function dotenv(string $value): string
    {
        if (! str_contains($value, "'") && ! str_ends_with($value, '\\')) {
            return "'".$value."'";
        }

        return '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '$$'], $value).'"';
    }

    /**
     * A value written directly into a generated compose file, such as an `environment:` entry,
     * a command, or a healthcheck. Compose does not parse quotes there and only interpolates "$".
     */
    public static function composeFile(string $value): string
    {
        return str_replace('$', '$$', $value);
    }
}
