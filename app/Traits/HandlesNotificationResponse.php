<?php

namespace App\Traits;

use App\Rules\SafeWebhookUrl;
use Closure;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Shared response handling for the notification jobs. The job must have a
 * nullable `$settings` property with a model that uses TracksDeliveryFailures
 * (null for test notifications, so that they never count).
 */
trait HandlesNotificationResponse
{
    /**
     * Send the request and replace HTTP client exceptions, whose messages contain
     * the full URL (bot token, secret webhook path), with one that only names the
     * scheme and host. The original exception is not kept as `previous`, so the
     * URL never reaches failed_jobs or the logs.
     *
     * @param  Closure(): Response  $send
     */
    protected function sendNotificationRequest(string $channel, string $url, Closure $send): Response
    {
        try {
            return $send();
        } catch (ConnectionException|RequestException|TransferException $e) {
            throw new RuntimeException(sprintf(
                '%s notification request to %s failed (%s).',
                $channel,
                SafeWebhookUrl::redactedUrlForLog($url),
                class_basename($e),
            ));
        }
    }

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

        // The response body is never part of the reason: it can echo tokens or webhook secrets.
        $reason = "{$channel} returned HTTP {$response->status()} (".(HttpResponse::$statusTexts[$response->status()] ?? 'Client Error').').';

        if ($isConfigurationError) {
            $this->settings?->recordDeliveryFailure($reason);

            return;
        }

        Log::warning("{$channel} rejected the notification", ['reason' => $reason]);
    }
}
