<?php

namespace App\Http\Middleware;

use App\Models\PersonalAccessToken;
use App\Models\Team;
use App\Notifications\ApiTokenFailingNotification;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Slow down API clients that keep sending requests that fail (401, 403, and 404 without a token).
 *
 * Global middleware, so requests to API routes that do not exist count too.
 * Each failed request counts for its bearer token (or the source IP without a token).
 * A 404 counts only without a token: a client with a token often checks if a resource exists.
 * When a client reaches the limit in one minute, all its requests get 429. The block is
 * 1 minute, then 5 and 15 minutes when the client reaches the limit again within one hour.
 * The team of the token gets one notification each day.
 */
class ThrottleFailedApiRequests
{
    private const DECAY_SECONDS = 60;

    /** @var array<int, int> */
    private const BLOCK_SECONDS = [60, 300, 900];

    private const STRIKE_MEMORY_SECONDS = 3600;

    private const NOTIFICATION_INTERVAL_SECONDS = 86400;

    public function handle(Request $request, Closure $next): Response
    {
        $limit = (int) config('api.failed_request_rate_limit');
        if ($limit <= 0 || ! $request->is('api/*') || $request->is('api/health', 'api/v1/health')) {
            return $next($request);
        }

        $key = $this->key($request);

        $retryAfter = $this->blockedFor($key);
        if ($retryAfter > 0) {
            return response()->json([
                'message' => "Too many failed API requests. Retry after {$retryAfter} seconds.",
            ], 429, ['Retry-After' => $retryAfter]);
        }

        $response = $next($request);

        if ($this->isCountedFailure($request, $response->getStatusCode())
            && RateLimiter::hit($key, self::DECAY_SECONDS) >= $limit) {
            $this->block($key);
            $this->notifyTeam($request, $response->getStatusCode());
        }

        return $response;
    }

    private function isCountedFailure(Request $request, int $statusCode): bool
    {
        return in_array($statusCode, [401, 403], true)
            || ($statusCode === 404 && ! $request->bearerToken());
    }

    private function blockedFor(string $key): int
    {
        return max(0, (int) Cache::get("{$key}:blocked-until", 0) - now()->getTimestamp());
    }

    /**
     * Block the client, longer each time it reaches the limit again within one hour.
     */
    private function block(string $key): void
    {
        $strikes = (int) Cache::get("{$key}:strikes", 0) + 1;
        Cache::put("{$key}:strikes", $strikes, self::STRIKE_MEMORY_SECONDS);

        $seconds = self::BLOCK_SECONDS[min($strikes, count(self::BLOCK_SECONDS)) - 1];
        Cache::put("{$key}:blocked-until", now()->getTimestamp() + $seconds, $seconds);
        RateLimiter::clear($key);
    }

    private function key(Request $request): string
    {
        $bearerToken = $request->bearerToken();

        return 'api-failed-requests:'.($bearerToken
            ? 'token:'.hash('sha256', $bearerToken)
            : 'ip:'.client_ip($request));
    }

    private function notifyTeam(Request $request, int $statusCode): void
    {
        try {
            $bearerToken = $request->bearerToken();
            $token = $bearerToken ? PersonalAccessToken::findToken($bearerToken) : null;
            if (! $token instanceof PersonalAccessToken || ! $token->team_id) {
                return;
            }

            if (! Cache::add("api-token-failing-notified:{$token->id}", true, self::NOTIFICATION_INTERVAL_SECONDS)) {
                return;
            }

            Team::find($token->team_id)?->notify(new ApiTokenFailingNotification(
                token: $token,
                failedRequests: (int) config('api.failed_request_rate_limit'),
                lastRequest: $request->method().' /'.$request->path().' → '.$statusCode,
            ));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
