<?php

namespace App\Jobs;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncStripeSubscriptionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800; // 30 minutes max

    public function __construct(public bool $fix = false)
    {
        $this->onQueue('high');
    }

    public function handle(): array
    {
        if (! isCloud() || ! isStripe()) {
            return ['error' => 'Not running on Cloud or Stripe not configured'];
        }

        $subscriptions = Subscription::whereNotNull('stripe_subscription_id')
            ->where('stripe_invoice_paid', true)
            ->get();

        $stripe = new \Stripe\StripeClient(config('subscription.stripe_api_key'));
        $discrepancies = [];
        $errors = [];

        foreach ($subscriptions as $subscription) {
            try {
                $stripeSubscription = $stripe->subscriptions->retrieve(
                    $subscription->stripe_subscription_id
                );

                // Check if Stripe says cancelled but we think it's active
                if (in_array($stripeSubscription->status, ['canceled', 'incomplete_expired', 'unpaid'])) {
                    $discrepancies[] = [
                        'subscription_id' => $subscription->id,
                        'team_id' => $subscription->team_id,
                        'stripe_subscription_id' => $subscription->stripe_subscription_id,
                        'stripe_status' => $stripeSubscription->status,
                    ];

                    // Only fix if --fix flag is passed
                    if ($this->fix) {
                        $subscription->update([
                            'stripe_invoice_paid' => false,
                            'stripe_past_due' => false,
                        ]);

                        if ($stripeSubscription->status === 'canceled') {
                            $subscription->team?->subscriptionEnded();
                        }
                    }
                }

                // Small delay to avoid Stripe rate limits
                usleep(100000); // 100ms
            } catch (\Exception $e) {
                $errors[] = [
                    'subscription_id' => $subscription->id,
                    'error' => $e->getMessage(),
                ];
            }
        }

        // Only notify if discrepancies found and fixed
        if ($this->fix && count($discrepancies) > 0) {
            send_internal_notification(
                'SyncStripeSubscriptionsJob: Fixed '.count($discrepancies)." discrepancies:\n".
                json_encode($discrepancies, JSON_PRETTY_PRINT)
            );
        }

        return [
            'total_checked' => $subscriptions->count(),
            'discrepancies' => $discrepancies,
            'errors' => $errors,
            'fixed' => $this->fix,
        ];
    }
}
