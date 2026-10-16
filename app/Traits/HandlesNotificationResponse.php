<?php

namespace App\Traits;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Shared response handling for the notification jobs. The job must have a
 * nullable `$settings` property with a model that uses TracksDeliveryFailures
 * (null for test notifications, so that they never count).
 */
trait HandlesNotificationResponse
{
    /**
     * Rate limits and server errors are retried by the queue. A configuration
     * error (invalid token, deleted webhook) counts as a delivery failure and
     * is not retried. Other client errors come from our payload: they are only
     * logged, because they must never disable the user's channel.
     */
    protected function handleNotificationResponse(Response $response, string $channel, bool $isConfigurationError): void
    {
        if ($response->successful()) {
            $this->settings?->recordDeliverySuccess();

            return;
        }

        if ($response->status() === 429 || $response->serverError()) {
            throw new RuntimeException("{$channel} notification failed with {$response->status()} status code.");
        }

        $reason = "{$channel} returned HTTP {$response->status()}: ".Str::limit($response->body(), 200);

        if ($isConfigurationError) {
            $this->settings?->recordDeliveryFailure($reason);

            return;
        }

        Log::warning("{$channel} rejected the notification", ['reason' => $reason]);
    }
}
