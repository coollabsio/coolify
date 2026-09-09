<?php

namespace App\Services;

use DateTimeInterface;

/**
 * Tracks a failed database migration during an upgrade.
 *
 * The web server (php-fpm/nginx) starts independently of the s6 migration chain,
 * so a broken migration still passes the health check and the upgrade reports
 * success. This persistent marker lets the app surface the real failure instead.
 *
 * The record/clear/current methods take an explicit path, so they can be unit tested
 * without booting Laravel; the default path() helper resolves storage_path() and does
 * require the framework. Filesystem problems are reported via error_log.
 */
class MigrationFailure
{
    public static function path(): string
    {
        return storage_path('app/backups/.coolify-migration-failure.json');
    }

    public static function record(string $message, ?DateTimeInterface $now = null, ?string $path = null): void
    {
        $path = $path ?? self::path();
        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            error_log("MigrationFailure: unable to create directory {$directory}; migration failure not recorded.");

            return;
        }

        $now = $now ?? new \DateTime;
        $payload = json_encode([
            'message' => $message,
            'failed_at' => $now->format(DateTimeInterface::ATOM),
        ], JSON_PRETTY_PRINT);

        // Write to a temp file and rename so a reader never sees a half-written marker.
        $temporary = $path.'.'.getmypid().'.tmp';
        if (@file_put_contents($temporary, $payload) === false || ! @rename($temporary, $path)) {
            @unlink($temporary);
            error_log("MigrationFailure: unable to write marker at {$path}; migration failure not recorded.");
        }
    }

    public static function clear(?string $path = null): void
    {
        $path = $path ?? self::path();
        if (is_file($path) && ! @unlink($path)) {
            error_log("MigrationFailure: unable to remove stale marker at {$path}.");
        }
    }

    /**
     * @return array{message: string, failed_at: ?string}|null
     */
    public static function current(?string $path = null): ?array
    {
        $path = $path ?? self::path();
        if (! is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            error_log("MigrationFailure: marker at {$path} exists but could not be read.");

            return null;
        }
        if (trim($raw) === '') {
            return null;
        }

        $data = json_decode($raw, true);
        // Use a strict empty-string check rather than empty(): a real failure message
        // of "0" is falsy but still a genuine failure that must be surfaced.
        if (! is_array($data) || ! isset($data['message']) || ! is_scalar($data['message']) || (string) $data['message'] === '') {
            return null;
        }

        return [
            'message' => (string) $data['message'],
            'failed_at' => isset($data['failed_at']) ? (string) $data['failed_at'] : null,
        ];
    }
}
