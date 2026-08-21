<?php

namespace App\Services;

use DateTimeInterface;

/**
 * Tracks a failed database migration during an upgrade.
 *
 * The web server (php-fpm/nginx) starts independently of the s6 migration chain,
 * so a broken migration still passes the health check and the upgrade reports
 * success. This persistent marker lets the app surface the real failure instead.
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
        if (! is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }

        $now = $now ?? new \DateTime;
        @file_put_contents($path, json_encode([
            'message' => $message,
            'failed_at' => $now->format(DateTimeInterface::ATOM),
        ], JSON_PRETTY_PRINT));
    }

    public static function clear(?string $path = null): void
    {
        $path = $path ?? self::path();
        if (is_file($path)) {
            @unlink($path);
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
        if ($raw === false || trim($raw) === '') {
            return null;
        }

        $data = json_decode($raw, true);
        if (! is_array($data) || empty($data['message'])) {
            return null;
        }

        return [
            'message' => (string) $data['message'],
            'failed_at' => isset($data['failed_at']) ? (string) $data['failed_at'] : null,
        ];
    }
}
