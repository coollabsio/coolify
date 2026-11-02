<?php

namespace App\Support;

/**
 * Builds the shell command of a Coolify health check. It runs inside the container,
 * as the `CMD-SHELL` form of a Docker health check or a Podman `--health-cmd`.
 */
class HealthCheckCommand
{
    /** The characters that a user command can use. Shell operators are blocked. */
    public const COMMAND_PATTERN = '/^[a-zA-Z0-9 \-_.\/:=@,+]+$/';

    public const MAX_COMMAND_LENGTH = 1000;

    /** The user command on one line, or null when it is not allowed. */
    public static function command(?string $command): ?string
    {
        $command = str_replace(["\r\n", "\r", "\n"], ' ', (string) $command);
        if ($command === '' || ! preg_match(self::COMMAND_PATTERN, $command) || strlen($command) > self::MAX_COMMAND_LENGTH) {
            return null;
        }

        return $command;
    }

    /**
     * A request with curl, or wget when the image has no curl. Invalid values fall back to safe defaults.
     *
     * @return array{command: string, description: string}
     */
    public static function http(?string $method, ?string $scheme, ?string $host, int $port, ?string $path): array
    {
        $method = self::sanitize((string) $method, '/^[A-Z]+$/', 'GET');
        $scheme = self::sanitize((string) $scheme, '/^https?$/', 'http');
        $host = ValidationPatterns::isValidHealthCheckHost($host) ? formatHostForUrl($host) : 'localhost';
        $path = $path ? self::sanitize($path, '#^[a-zA-Z0-9/\-_.~%,;]+$#', '/') : '/';
        $url = escapeshellarg("{$scheme}://{$host}:{$port}{$path}");
        $escapedMethod = escapeshellarg($method);

        return [
            'command' => "curl -s -X {$escapedMethod} -f {$url} > /dev/null || wget -q -O- {$url} > /dev/null || exit 1",
            'description' => "{$method}: {$scheme}://{$host}:{$port}{$path}",
        ];
    }

    private static function sanitize(string $value, string $pattern, string $default): string
    {
        return preg_match($pattern, $value) ? $value : $default;
    }
}
