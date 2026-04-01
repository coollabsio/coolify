<?php

namespace App\Helpers;

class DockerHelper
{
    /**
     * Normalize a memory limit value for Docker Compose.
     *
     * Docker Compose interprets bare numbers as bytes (e.g., "4096" = 4096 bytes = 4KB).
     * Coolify stores memory limits as bare numbers representing megabytes (e.g., "4096" = 4096MB).
     * This method appends the "m" suffix when the value is a bare number to ensure
     * Docker interprets it correctly as megabytes.
     *
     * Values that already have a unit suffix (k, m, g, b) or are "0" (unlimited) are returned as-is.
     *
     * @param  string|int|null  $value  The memory limit value from the database
     * @return string|int The normalized value safe for Docker Compose
     */
    public static function normalizeMemoryLimit(string|int|null $value): string|int
    {
        if ($value === null || $value === '' || $value === '0' || $value === 0) {
            return $value ?? 0;
        }

        $stringValue = (string) $value;

        // If it already has a unit suffix, return as-is
        if (preg_match('/[bkmgBKMG]$/', $stringValue)) {
            return $stringValue;
        }

        // Bare number — append 'm' to interpret as megabytes
        if (is_numeric($stringValue)) {
            return $stringValue.'m';
        }

        return $value;
    }
}
