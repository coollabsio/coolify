<?php

namespace App\Jobs;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class VerifyStripeSubscriptionStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public function __construct(public Subscription $subscription)
    {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        // If no subscription ID yet, try to find it via customer
        if (! $this->subscription->stripe_subscription_id &&
            $this->subscription->stripe_customer_id) {
            try {
                $stripe = new \Stripe\StripeClient(config('subscription.stripe_api_key'));
                $subscriptions = $stripe->subscriptions->all([
                    'customer' => $this->subscription->stripe_customer_id,
                    'limit' => 1,
                ]);

                if ($subscriptions->data) {
                    $this->subscription->update([
                        'stripe_subscription_id' => $subscriptions->data[0]->id,
                    ]);
                }
            } catch (\Exception $e) {
                // Continue without subscription ID
            }
        }

        if (! $this->subscription->stripe_subscription_id) {
            return;
        }

        try {
            $stripe = new \Stripe\StripeClient(config('subscription.stripe_api_key'));
            $stripeSubscription = $stripe->subscriptions->retrieve(
                $this->subscription->stripe_subscription_id
            );

            switch ($stripeSubscription->status) {
                case 'active':
                    $this->subscription->update([
                        'stripe_invoice_paid' => true,
                        'stripe_past_due' => false,
                        'stripe_cancel_at_period_end' => $stripeSubscription->cancel_at_period_end,
                    ]);
                    break;

                case 'past_due':
                    // Keep subscription active but mark as past_due
                    $this->subscription->update([
                        'stripe_invoice_paid' => true,
                        'stripe_past_due' => true,
                        'stripe_cancel_at_period_end' => $stripeSubscription->cancel_at_period_end,
                    ]);
                    break;

                case 'canceled':
                case 'incomplete_expired':
                case 'unpaid':
                    // Ensure subscription is marked as inactive
                    $this->subscription->update([
                        'stripe_invoice_paid' => false,
                        'stripe_past_due' => false,
                    ]);

                    // Trigger subscription ended logic if canceled
                    if ($stripeSubscription->status === 'canceled') {
                        $team = $this->subscription->team;
                        if ($team) {
                            $team->subscriptionEnded();
                        }
                    }
                    break;

                default:
                    send_internal_notification(
                        'Unknown subscription status in VerifyStripeSubscriptionStatusJob: '.$stripeSubscription->status.
                        ' for customer: '.$this->subscription->stripe_customer_id
                    );
                    break;
            }
        } catch (\Exception $e) {
            send_internal_notification(
                'VerifyStripeSubscriptionStatusJob failed for subscription ID '.$this->subscription->id.': '.$e->getMessage()
            );
        }
    }
}
