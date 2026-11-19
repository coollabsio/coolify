<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\StripeProcessJob;
use Illuminate\Http\Request;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use Throwable;

class Stripe extends Controller
{
    public function events(Request $request)
    {
        try {
            $apiKey = config('subscription.stripe_api_key');
            $webhookSecret = config('subscription.stripe_webhook_secret');
            if (! is_string($apiKey) || trim($apiKey) === '' || ! is_string($webhookSecret) || trim($webhookSecret) === '') {
                auditLogWebhookFailure('stripe', 'stripe_not_configured');

                return response('Invalid signature.', 400);
            }

            $signature = $request->header('Stripe-Signature');
            $event = Webhook::constructEvent(
                $request->getContent(),
                $signature,
                $webhookSecret
            );
            StripeProcessJob::dispatch($event);

            return response('Webhook received. Cool cool cool cool cool.', 200);
        } catch (SignatureVerificationException) {
            auditLogWebhookFailure('stripe', 'invalid_signature');

            return response('Invalid signature.', 400);
        } catch (Throwable) {
            auditLogWebhookFailure('stripe', 'invalid_payload');

            return response('Invalid webhook.', 400);
        }
    }
}
