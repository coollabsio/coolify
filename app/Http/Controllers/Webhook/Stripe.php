<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\ServerLimitCheckJob;
use App\Jobs\SubscriptionInvoiceFailedJob;
use App\Jobs\SubscriptionTrialEndedJob;
use App\Jobs\SubscriptionTrialEndsSoonJob;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\Webhook;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

class Stripe extends Controller
{
    public function events(Request $request)
    {
        try {
            if (app()->isDownForMaintenance()) {
                ray('Maintenance mode is on');
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

                return;
            }
            $webhookSecret = config('subscription.stripe_webhook_secret');
            $signature = $request->header('Stripe-Signature');
            $excludedPlans = config('subscription.stripe_excluded_plans');
            $event = \Stripe\Webhook::constructEvent(
                $request->getContent(),
                $signature,
                $webhookSecret
            );
            $webhook = Webhook::create([
                'type' => 'stripe',
                'payload' => $request->getContent(),
            ]);
            $type = data_get($event, 'type');
            $data = data_get($event, 'data.object');
            switch ($type) {
                case 'radar.early_fraud_warning.created':
                    $stripe = new \Stripe\StripeClient(config('subscription.stripe_api_key'));
                    $id = data_get($data, 'id');
                    $charge = data_get($data, 'charge');
                    if ($charge) {
                        $stripe->refunds->create(['charge' => $charge]);
                    }
                    $pi = data_get($data, 'payment_intent');
                    $piData = $stripe->paymentIntents->retrieve($pi, []);
                    $customerId = data_get($piData, 'customer');
                    $subscription = Subscription::where('stripe_customer_id', $customerId)->first();
                    if (! $subscription) {
                        Sleep::for(5)->seconds();
                        $subscription = Subscription::where('stripe_customer_id', $customerId)->first();
                    }
                    if (! $subscription) {
                        Sleep::for(5)->seconds();
                        $subscription = Subscription::where('stripe_customer_id', $customerId)->first();
                    }
                    if ($subscription) {
                        $subscriptionId = data_get($subscription, 'stripe_subscription_id');
                        $stripe->subscriptions->cancel($subscriptionId, []);
                        $subscription->update([
                            'stripe_invoice_paid' => false,
                        ]);
                    }
                    send_internal_notification("Early fraud warning created Refunded, subscription canceled. Charge: {$charge}, id: {$id}, pi: {$pi}");
                    break;
                case 'checkout.session.completed':
                    $clientReferenceId = data_get($data, 'client_reference_id');
                    if (is_null($clientReferenceId)) {
                        send_internal_notification('Checkout session completed without client reference id.');
                        break;
                    }
                    $userId = Str::before($clientReferenceId, ':');
                    $teamId = Str::after($clientReferenceId, ':');
                    $subscriptionId = data_get($data, 'subscription');
                    $customerId = data_get($data, 'customer');
                    $team = Team::find($teamId);
                    $found = $team->members->where('id', $userId)->first();
                    if (! $found->isAdmin()) {
                        send_internal_notification("User {$userId} is not an admin or owner of team {$team->id}, customerid: {$customerId}, subscriptionid: {$subscriptionId}.");
                        throw new Exception("User {$userId} is not an admin or owner of team {$team->id}, customerid: {$customerId}, subscriptionid: {$subscriptionId}.");
                    }
                    $subscription = Subscription::where('team_id', $teamId)->first();
                    if ($subscription) {
                        // send_internal_notification('Old subscription activated for team: '.$teamId);
                        $subscription->update([
                            'stripe_subscription_id' => $subscriptionId,
                            'stripe_customer_id' => $customerId,
                            'stripe_invoice_paid' => true,
                        ]);
                    } else {
                        // send_internal_notification('New subscription for team: '.$teamId);
                        Subscription::create([
                            'team_id' => $teamId,
                            'stripe_subscription_id' => $subscriptionId,
                            'stripe_customer_id' => $customerId,
                            'stripe_invoice_paid' => true,
                        ]);
                    }
                    break;
                case 'invoice.paid':
                    $customerId = data_get($data, 'customer');
                    $planId = data_get($data, 'lines.data.0.plan.id');
                    if (Str::contains($excludedPlans, $planId)) {
                        // send_internal_notification('Subscription excluded.');
                        break;
                    }
                    $subscription = Subscription::where('stripe_customer_id', $customerId)->first();
                    if (! $subscription) {
                        Sleep::for(5)->seconds();
                        $subscription = Subscription::where('stripe_customer_id', $customerId)->firstOrFail();
                    }
                    $subscription->update([
                        'stripe_invoice_paid' => true,
                    ]);
                    break;
                case 'invoice.payment_failed':
                    $customerId = data_get($data, 'customer');
                    $subscription = Subscription::where('stripe_customer_id', $customerId)->first();
                    if (! $subscription) {
                        // send_internal_notification('invoice.payment_failed failed but no subscription found in Coolify for customer: '.$customerId);

                        return response('No subscription found in Coolify.');
                    }
                    $team = data_get($subscription, 'team');
                    if (! $team) {
                        // send_internal_notification('invoice.payment_failed failed but no team found in Coolify for customer: '.$customerId);

                        return response('No team found in Coolify.');
                    }
                    if (! $subscription->stripe_invoice_paid) {
                        SubscriptionInvoiceFailedJob::dispatch($team);
                        // send_internal_notification('Invoice payment failed: '.$customerId);
                    } else {
                        // send_internal_notification('Invoice payment failed but already paid: '.$customerId);
                    }
                    break;
                case 'payment_intent.payment_failed':
                    $customerId = data_get($data, 'customer');
                    $subscription = Subscription::where('stripe_customer_id', $customerId)->first();
                    if (! $subscription) {
                        // send_internal_notification('payment_intent.payment_failed, no subscription found in Coolify for customer: '.$customerId);

                        return response('No subscription found in Coolify.');
                    }
                    if ($subscription->stripe_invoice_paid) {
                        // send_internal_notification('payment_intent.payment_failed but invoice is active for customer: '.$customerId);

                        return;
                    }
                    send_internal_notification('Subscription payment failed for customer: '.$customerId);
                    break;
                case 'customer.subscription.updated':
                    $customerId = data_get($data, 'customer');
                    $status = data_get($data, 'status');
                    $subscriptionId = data_get($data, 'items.data.0.subscription');
                    $planId = data_get($data, 'items.data.0.plan.id');
                    if (Str::contains($excludedPlans, $planId)) {
                        // send_internal_notification('Subscription excluded.');
                        break;
                    }
                    $subscription = Subscription::where('stripe_customer_id', $customerId)->first();
                    if (! $subscription) {
                        Sleep::for(5)->seconds();
                        $subscription = Subscription::where('stripe_customer_id', $customerId)->first();
                    }
                    if (! $subscription) {
                        if ($status === 'incomplete_expired') {
                            // send_internal_notification('Subscription incomplete expired for customer: '.$customerId);

                            return response('Subscription incomplete expired', 200);
                        }
                        // send_internal_notification('No subscription found for: '.$customerId);

                        return response('No subscription found', 400);
                    }
                    $trialEndedAlready = data_get($subscription, 'stripe_trial_already_ended');
                    $cancelAtPeriodEnd = data_get($data, 'cancel_at_period_end');
                    $alreadyCancelAtPeriodEnd = data_get($subscription, 'stripe_cancel_at_period_end');
                    $feedback = data_get($data, 'cancellation_details.feedback');
                    $comment = data_get($data, 'cancellation_details.comment');
                    $lookup_key = data_get($data, 'items.data.0.price.lookup_key');
                    if (str($lookup_key)->contains('ultimate') || str($lookup_key)->contains('dynamic')) {
                        if (str($lookup_key)->contains('dynamic')) {
                            $quantity = data_get($data, 'items.data.0.quantity', 2);
                        } else {
                            $quantity = data_get($data, 'items.data.0.quantity', 10);
                        }
                        $team = data_get($subscription, 'team');
                        if ($team) {
                            $team->update([
                                'custom_server_limit' => $quantity,
                            ]);
                        }
                        ServerLimitCheckJob::dispatch($team);
                    }
                    $subscription->update([
                        'stripe_feedback' => $feedback,
                        'stripe_comment' => $comment,
                        'stripe_plan_id' => $planId,
                        'stripe_cancel_at_period_end' => $cancelAtPeriodEnd,
                    ]);
                    if ($status === 'paused' || $status === 'incomplete_expired') {
                        $subscription->update([
                            'stripe_invoice_paid' => false,
                        ]);
                        // send_internal_notification('Subscription paused or incomplete for customer: '.$customerId);
                    }

                    // Trial ended but subscribed, reactive servers
                    if ($trialEndedAlready && $status === 'active') {
                        $team = data_get($subscription, 'team');
                        $team->trialEndedButSubscribed();
                    }

                    if ($feedback) {
                        $reason = "Cancellation feedback for {$customerId}: '".$feedback."'";
                        if ($comment) {
                            $reason .= ' with comment: \''.$comment."'";
                        }
                        // send_internal_notification($reason);
                    }
                    if ($alreadyCancelAtPeriodEnd !== $cancelAtPeriodEnd) {
                        if ($cancelAtPeriodEnd) {
                            // send_internal_notification('Subscription cancelled at period end for team: ' . $subscription->team->id);
                        } else {
                            // send_internal_notification('customer.subscription.updated for customer: '.$customerId);
                        }
                    }
                    break;
                case 'customer.subscription.deleted':
                    // End subscription
                    $customerId = data_get($data, 'customer');
                    $subscription = Subscription::where('stripe_customer_id', $customerId)->firstOrFail();
                    $team = data_get($subscription, 'team');
                    if ($team) {
                        $team->trialEnded();
                    }
                    $subscription->update([
                        'stripe_subscription_id' => null,
                        'stripe_plan_id' => null,
                        'stripe_cancel_at_period_end' => false,
                        'stripe_invoice_paid' => false,
                        'stripe_trial_already_ended' => false,
                    ]);
                    // send_internal_notification('customer.subscription.deleted for customer: '.$customerId);
                    break;
                case 'customer.subscription.trial_will_end':
                    // Not used for now
                    $customerId = data_get($data, 'customer');
                    $subscription = Subscription::where('stripe_customer_id', $customerId)->firstOrFail();
                    $team = data_get($subscription, 'team');
                    if (! $team) {
                        throw new Exception('No team found for subscription: '.$subscription->id);
                    }
                    SubscriptionTrialEndsSoonJob::dispatch($team);
                    break;
                case 'customer.subscription.paused':
                    $customerId = data_get($data, 'customer');
                    $subscription = Subscription::where('stripe_customer_id', $customerId)->firstOrFail();
                    $team = data_get($subscription, 'team');
                    if (! $team) {
                        throw new Exception('No team found for subscription: '.$subscription->id);
                    }
                    $team->trialEnded();
                    $subscription->update([
                        'stripe_trial_already_ended' => true,
                        'stripe_invoice_paid' => false,
                    ]);
                    SubscriptionTrialEndedJob::dispatch($team);
                    // send_internal_notification('Subscription paused for customer: '.$customerId);
                    break;
                default:
                    // Unhandled event type
            }
        } catch (Exception $e) {
            if ($type !== 'payment_intent.payment_failed') {
                send_internal_notification("Subscription webhook ($type) failed: ".$e->getMessage());
            }
            $webhook->update([
                'status' => 'failed',
                'failure_reason' => $e->getMessage(),
            ]);

            return response($e->getMessage(), 400);
        }
    }
}
