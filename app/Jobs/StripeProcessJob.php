<?php

namespace App\Jobs;

use App\Models\Subscription;
use App\Models\Team;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

class StripeProcessJob implements ShouldQueue
{
    use Queueable;

    public $type;

    public $webhook;

    public $tries = 3;

    public function __construct(public $event)
    {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        try {
            $excludedPlans = config('subscription.stripe_excluded_plans');

            $type = data_get($this->event, 'type');
            $this->type = $type;
            $data = data_get($this->event, 'data.object');
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
                    if ($subscription) {
                        $subscriptionId = data_get($subscription, 'stripe_subscription_id');
                        $stripe->subscriptions->cancel($subscriptionId, []);
                        $subscription->update([
                            'stripe_invoice_paid' => false,
                        ]);
                        send_internal_notification("Early fraud warning created Refunded, subscription canceled. Charge: {$charge}, id: {$id}, pi: {$pi}");
                    } else {
                        send_internal_notification("Early fraud warning: subscription not found. Charge: {$charge}, id: {$id}, pi: {$pi}");
                        throw new \RuntimeException("Early fraud warning: subscription not found. Charge: {$charge}, id: {$id}, pi: {$pi}");
                    }
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
                        throw new \RuntimeException("User {$userId} is not an admin or owner of team {$team->id}, customerid: {$customerId}, subscriptionid: {$subscriptionId}.");
                    }
                    $subscription = Subscription::where('team_id', $teamId)->first();
                    if ($subscription) {
                        send_internal_notification('Old subscription activated for team: '.$teamId);
                        $subscription->update([
                            'stripe_subscription_id' => $subscriptionId,
                            'stripe_customer_id' => $customerId,
                            'stripe_invoice_paid' => true,
                        ]);
                    } else {
                        send_internal_notification('New subscription for team: '.$teamId);
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
                        send_internal_notification('Subscription excluded.');
                        break;
                    }
                    $subscription = Subscription::where('stripe_customer_id', $customerId)->first();
                    if ($subscription) {
                        $subscription->update([
                            'stripe_invoice_paid' => true,
                        ]);
                    } else {
                        throw new \RuntimeException("No subscription found for customer: {$customerId}");
                    }
                    break;
                case 'invoice.payment_failed':
                    $customerId = data_get($data, 'customer');
                    $subscription = Subscription::where('stripe_customer_id', $customerId)->first();
                    if (! $subscription) {
                        send_internal_notification('invoice.payment_failed failed but no subscription found in Coolify for customer: '.$customerId);
                        throw new \RuntimeException("No subscription found for customer: {$customerId}");
                    }
                    $team = data_get($subscription, 'team');
                    if (! $team) {
                        send_internal_notification('invoice.payment_failed failed but no team found in Coolify for customer: '.$customerId);
                        throw new \RuntimeException("No team found in Coolify for customer: {$customerId}");
                    }
                    if (! $subscription->stripe_invoice_paid) {
                        SubscriptionInvoiceFailedJob::dispatch($team);
                        send_internal_notification('Invoice payment failed: '.$customerId);
                    } else {
                        send_internal_notification('Invoice payment failed but already paid: '.$customerId);
                    }
                    break;
                case 'payment_intent.payment_failed':
                    $customerId = data_get($data, 'customer');
                    $subscription = Subscription::where('stripe_customer_id', $customerId)->first();
                    if (! $subscription) {
                        send_internal_notification('payment_intent.payment_failed, no subscription found in Coolify for customer: '.$customerId);
                        throw new \RuntimeException("No subscription found in Coolify for customer: {$customerId}");
                    }
                    if ($subscription->stripe_invoice_paid) {
                        send_internal_notification('payment_intent.payment_failed but invoice is active for customer: '.$customerId);

                        return;
                    }
                    send_internal_notification('Subscription payment failed for customer: '.$customerId);
                    break;
                case 'customer.subscription.created':
                    $customerId = data_get($data, 'customer');
                    $subscriptionId = data_get($data, 'id');
                    $teamId = data_get($data, 'metadata.team_id');
                    $userId = data_get($data, 'metadata.user_id');
                    if (! $teamId || ! $userId) {
                        $subscription = Subscription::where('stripe_customer_id', $customerId)->first();
                        if ($subscription) {
                            throw new \RuntimeException("Subscription already exists for customer: {$customerId}");
                        }
                        throw new \RuntimeException('No team id or user id found');
                    }
                    $team = Team::find($teamId);
                    $found = $team->members->where('id', $userId)->first();
                    if (! $found->isAdmin()) {
                        send_internal_notification("User {$userId} is not an admin or owner of team {$team->id}, customerid: {$customerId}.");
                        throw new \RuntimeException("User {$userId} is not an admin or owner of team {$team->id}, customerid: {$customerId}.");
                    }
                    $subscription = Subscription::where('team_id', $teamId)->first();
                    if ($subscription) {
                        send_internal_notification("Subscription already exists for team: {$teamId}");
                        throw new \RuntimeException("Subscription already exists for team: {$teamId}");
                    } else {
                        Subscription::create([
                            'team_id' => $teamId,
                            'stripe_subscription_id' => $subscriptionId,
                            'stripe_customer_id' => $customerId,
                            'stripe_invoice_paid' => false,
                        ]);
                    }
                case 'customer.subscription.updated':
                    $teamId = data_get($data, 'metadata.team_id');
                    $userId = data_get($data, 'metadata.user_id');
                    $customerId = data_get($data, 'customer');
                    $status = data_get($data, 'status');
                    $subscriptionId = data_get($data, 'items.data.0.subscription') ?? data_get($data, 'id');
                    $planId = data_get($data, 'items.data.0.plan.id') ?? data_get($data, 'plan.id');
                    if (Str::contains($excludedPlans, $planId)) {
                        send_internal_notification('Subscription excluded.');
                        break;
                    }
                    $subscription = Subscription::where('stripe_customer_id', $customerId)->first();
                    if (! $subscription) {
                        if ($status === 'incomplete_expired') {
                            send_internal_notification('Subscription incomplete expired');
                            throw new \RuntimeException('Subscription incomplete expired');
                        }
                        if ($teamId) {
                            $subscription = Subscription::create([
                                'team_id' => $teamId,
                                'stripe_subscription_id' => $subscriptionId,
                                'stripe_customer_id' => $customerId,
                                'stripe_invoice_paid' => false,
                            ]);
                        } else {
                            send_internal_notification('No subscription and team id found');
                            throw new \RuntimeException('No subscription and team id found');
                        }
                    }
                    $cancelAtPeriodEnd = data_get($data, 'cancel_at_period_end');
                    $feedback = data_get($data, 'cancellation_details.feedback');
                    $comment = data_get($data, 'cancellation_details.comment');
                    $lookup_key = data_get($data, 'items.data.0.price.lookup_key');
                    if (str($lookup_key)->contains('dynamic')) {
                        $quantity = data_get($data, 'items.data.0.quantity', 2);
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
                        if ($subscription->stripe_subscription_id === $subscriptionId) {
                            $subscription->update([
                                'stripe_invoice_paid' => false,
                            ]);
                        }
                    }
                    if ($status === 'active') {
                        if ($subscription->stripe_subscription_id === $subscriptionId) {
                            $subscription->update([
                                'stripe_invoice_paid' => true,
                            ]);
                        }
                    }
                    if ($feedback) {
                        $reason = "Cancellation feedback for {$customerId}: '".$feedback."'";
                        if ($comment) {
                            $reason .= ' with comment: \''.$comment."'";
                        }
                    }

                    break;
                case 'customer.subscription.deleted':
                    $customerId = data_get($data, 'customer');
                    $subscriptionId = data_get($data, 'id');
                    $subscription = Subscription::where('stripe_customer_id', $customerId)->where('stripe_subscription_id', $subscriptionId)->first();
                    if ($subscription) {
                        $team = data_get($subscription, 'team');
                        if ($team) {
                            $team->subscriptionEnded();
                        } else {
                            send_internal_notification('Subscription deleted but no team found in Coolify for customer: '.$customerId);
                            throw new \RuntimeException("No team found in Coolify for customer: {$customerId}");
                        }
                    } else {
                        send_internal_notification('Subscription deleted but no subscription found in Coolify for customer: '.$customerId);
                        throw new \RuntimeException("No subscription found in Coolify for customer: {$customerId}");
                    }
                    break;
                default:
                    throw new \RuntimeException("Unhandled event type: {$type}");
            }
        } catch (\Exception $e) {
            send_internal_notification('StripeProcessJob error: '.$e->getMessage());
        }
    }
}
