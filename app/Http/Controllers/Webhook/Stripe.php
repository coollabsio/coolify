<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\StripeProcessJob;
use App\Models\Webhook;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class Stripe extends Controller
{
    protected $webhook;

    public function events(Request $request)
    {
        try {
            $webhookSecret = config('subscription.stripe_webhook_secret');
            $signature = $request->header('Stripe-Signature');
            $event = \Stripe\Webhook::constructEvent(
                $request->getContent(),
                $signature,
                $webhookSecret
            );
            if (app()->isDownForMaintenance()) {
                $epoch = now()->valueOf();
                $data = [
                    'attributes' => $request->attributes->all(),
                    'request' => $request->request->all(),
                    'query' => $request->query->all(),
                    'server' => $request->server->all(),
                    'files' => $request->files->all(),
                    'cookies' => $request->cookies->all(),
                    'headers' => $request->headers->all(),
                    'content' => $request->getContent(),
                ];
                $json = json_encode($data);
                Storage::disk('webhooks-during-maintenance')->put("{$epoch}_Stripe::events_stripe", $json);

                return response('Webhook received. Cool cool cool cool cool.', 200);
            }
            $this->webhook = Webhook::create([
                'type' => 'stripe',
                'payload' => $request->getContent(),
            ]);
            StripeProcessJob::dispatch($event);

            return response('Webhook received. Cool cool cool cool cool.', 200);
        } catch (Exception $e) {
            $this->webhook->update([
                'status' => 'failed',
                'failure_reason' => $e->getMessage(),
            ]);

            return response($e->getMessage(), 400);
        }
    }
}
