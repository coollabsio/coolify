<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\StripeProcessJob;
use Exception;
use Illuminate\Http\Request;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

class Stripe extends Controller
{
    public function events(Request $request)
    {
        try {
            $webhookSecret = config('subscription.stripe_webhook_secret');
            $signature = $request->header('Stripe-Signature');
            $event = Webhook::constructEvent(
                $request->getContent(),
                $signature,
                $webhookSecret
            );
            StripeProcessJob::dispatch($event);

            return response('Webhook received. Cool cool cool cool cool.', 200);
        } catch (SignatureVerificationException $e) {
            auditLogWebhookFailure('stripe', 'invalid_signature', [
                'error' => $e->getMessage(),
            ]);

            return response($e->getMessage(), 400);
        } catch (Exception $e) {
            return response($e->getMessage(), 400);
        }
    }
}
