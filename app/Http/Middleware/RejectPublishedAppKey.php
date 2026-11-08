<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks all HTTP requests while APP_KEY (or APP_PREVIOUS_KEYS) is a key that was published in this repository.
 *
 * Anyone can sign and encrypt data with a published key, so the instance is not safe to serve.
 * Console commands still run, so the key can be rotated with `php artisan app-key:reencrypt`.
 */
class RejectPublishedAppKey
{
    /**
     * Keys from example files that were shipped in this repository.
     *
     * @var list<string>
     */
    public const PUBLISHED_KEYS = [
        // .env.windows-docker-desktop.example (removed in v4.6.0)
        'base64:ssTlCmrIE/q7whnKMvT6DwURikg69COzGsAwFVROm80=',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! self::usesPublishedKey()) {
            return $next($request);
        }

        return response(
            "Coolify does not start: APP_KEY or APP_PREVIOUS_KEYS contains a key that is public.\n\n".
            "1. Generate a new key: openssl rand -base64 32\n".
            "2. In .env, set APP_PREVIOUS_KEYS to the old APP_KEY value and APP_KEY=base64:<new key>.\n".
            "3. Restart Coolify and run: docker exec coolify php artisan app-key:reencrypt --force\n".
            "4. Remove APP_PREVIOUS_KEYS from .env and restart Coolify.\n",
            503,
            ['Content-Type' => 'text/plain; charset=UTF-8'],
        );
    }

    public static function usesPublishedKey(): bool
    {
        $keys = [config('app.key'), ...(array) config('app.previous_keys', [])];

        return array_intersect(array_map(fn ($key) => trim((string) $key), $keys), self::PUBLISHED_KEYS) !== [];
    }
}
