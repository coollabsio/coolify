<?php

namespace App\Http\Controllers\Webhook\Concerns;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Throttles manual webhook deliveries that fail authentication.
 *
 * Only failed authentication attempts are counted, per provider and client IP,
 * so valid signed deliveries from shared IPs (Cloudflare, Git host IP pools)
 * are never dropped because of unrelated traffic.
 */
trait ThrottlesManualWebhookFailures
{
    protected const int MANUAL_WEBHOOK_MAX_FAILURES = 30;

    protected const int MANUAL_WEBHOOK_FAILURE_DECAY_SECONDS = 60;

    protected function manualWebhookFailureRateLimitKey(Request $request, string $provider): string
    {
        return "manual-webhook-failures:{$provider}:".auth_rate_limit_ip($request);
    }

    protected function hasTooManyManualWebhookFailures(Request $request, string $provider): bool
    {
        return RateLimiter::tooManyAttempts($this->manualWebhookFailureRateLimitKey($request, $provider), self::MANUAL_WEBHOOK_MAX_FAILURES);
    }

    protected function tooManyManualWebhookFailuresResponse(Request $request, string $provider): Response
    {
        $retryAfter = RateLimiter::availableIn($this->manualWebhookFailureRateLimitKey($request, $provider));

        return response([
            'status' => 'failed',
            'message' => 'Too many failed webhook authentication attempts. Try again later.',
        ], 429)->header('Retry-After', (string) max($retryAfter, 1));
    }

    protected function recordManualWebhookFailure(Request $request, string $provider): void
    {
        RateLimiter::hit($this->manualWebhookFailureRateLimitKey($request, $provider), self::MANUAL_WEBHOOK_FAILURE_DECAY_SECONDS);
    }
}
