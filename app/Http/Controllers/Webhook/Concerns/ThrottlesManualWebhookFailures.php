<?php

namespace App\Http\Controllers\Webhook\Concerns;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
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

    protected function recordManualWebhookFailure(string $failureKey): void
    {
        RateLimiter::hit($failureKey, self::MANUAL_WEBHOOK_FAILURE_DECAY_SECONDS);
    }
}
