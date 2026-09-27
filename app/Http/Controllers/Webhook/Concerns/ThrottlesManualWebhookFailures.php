<?php

namespace App\Http\Controllers\Webhook\Concerns;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Throttles manual webhook deliveries that fail authentication.
 *
 * Failures are counted per provider, client IP, repository and branch filter.
 * The repository and branch come from the payload and select the applications
 * whose secrets are checked, so a guesser can only exhaust the bucket of the
 * applications it targets. Git hosts deliver from shared egress IPs; one
 * misconfigured repository (wrong secret, deleted application, untracked
 * branch) therefore cannot lock out deliveries for other repositories.
 *
 * In one failure window, only distinct failed attempts are counted. An attempt
 * is identified by what the secret check depends on: the GitLab token, or the
 * pair (payload, signature) for HMAC providers. The check result for a repeated
 * attempt is already known, so a repeat does not test a new secret. A
 * misconfigured hook that sends the same wrong token, or a redelivery of the
 * same signed payload, therefore counts once, while every new guess still
 * counts. Attempts are stored only as keyed hashes, never as raw values.
 */
trait ThrottlesManualWebhookFailures
{
    protected const int MANUAL_WEBHOOK_MAX_FAILURES = 30;

    protected const int MANUAL_WEBHOOK_FAILURE_DECAY_SECONDS = 60;

    /**
     * @param  string  $fullName  Canonical repository path from manualWebhookRepositoryFullName().
     * @param  mixed  $branch  Branch used to select applications, or null when all branches match.
     */
    protected function manualWebhookFailureRateLimitKey(Request $request, string $provider, string $fullName, mixed $branch): string
    {
        $scope = json_encode([
            mb_strtolower($fullName),
            is_scalar($branch) ? (string) $branch : null,
        ]);

        return "manual-webhook-failures:{$provider}:".auth_rate_limit_ip($request).':'.hash('sha256', (string) $scope);
    }

    /**
     * Attempt identity for a static token, such as the GitLab X-Gitlab-Token.
     */
    protected function manualWebhookTokenAttempt(string $token): string
    {
        return 'token:'.$token;
    }

    /**
     * Attempt identity for an HMAC signature. The signature depends on the raw
     * payload, so the same signature for another payload is a new attempt.
     */
    protected function manualWebhookSignedPayloadAttempt(Request $request, string $signature): string
    {
        return 'hmac:'.hash('sha256', $request->getContent()).':'.$signature;
    }

    protected function hasTooManyManualWebhookFailures(string $failureKey): bool
    {
        return RateLimiter::tooManyAttempts($failureKey, self::MANUAL_WEBHOOK_MAX_FAILURES);
    }

    protected function tooManyManualWebhookFailuresResponse(string $failureKey): Response
    {
        $retryAfter = RateLimiter::availableIn($failureKey);

        return response([
            'status' => 'failed',
            'message' => 'Too many failed webhook authentication attempts. Try again later.',
        ], 429)->header('Retry-After', (string) max($retryAfter, 1));
    }

    /**
     * Count a failed attempt, unless the same attempt was already counted in
     * the current failure window.
     *
     * The rate limiter counter stays the source of truth. The set of seen
     * attempts only suppresses repeats. It is reset when a new window starts
     * and holds at most MANUAL_WEBHOOK_MAX_FAILURES entries, because the scope
     * is locked when the counter reaches that value. A lost update of the set
     * (concurrent requests) can only count a repeat again, never skip a new
     * attempt.
     */
    protected function recordManualWebhookFailure(string $failureKey, string $attempt): void
    {
        $store = $this->manualWebhookFailureStore();
        $seenKey = $failureKey.':seen';
        $marker = hash_hmac('sha256', 'manual-webhook-failure:'.$attempt, (string) config('app.key'));

        $seen = RateLimiter::attempts($failureKey) > 0 ? $store->get($seenKey) : null;
        $seen = is_array($seen) ? $seen : [];

        if (in_array($marker, $seen, true)) {
            return;
        }

        RateLimiter::hit($failureKey, self::MANUAL_WEBHOOK_FAILURE_DECAY_SECONDS);

        if (count($seen) < self::MANUAL_WEBHOOK_MAX_FAILURES) {
            $seen[] = $marker;
            $store->put($seenKey, $seen, self::MANUAL_WEBHOOK_FAILURE_DECAY_SECONDS);
        }
    }

    /**
     * The cache store of the rate limiter, so the seen attempts live next to
     * the counter.
     */
    protected function manualWebhookFailureStore(): Repository
    {
        return Cache::store(config('cache.limiter'));
    }
}
