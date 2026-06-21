<?php

namespace App\Jobs;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncStripeSubscriptionsJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800; // 30 minutes max

    public function __construct(public bool $fix = false)
    {
        $this->onQueue('high');
    }

    public function handle(?\Closure $onProgress = null): array
    {
        if (! isCloud() || ! isStripe()) {
            return ['error' => 'Not running on Cloud or Stripe not configured'];
        }

        $subscriptions = Subscription::whereNotNull('stripe_subscription_id')
            ->where('stripe_invoice_paid', true)
            ->get();

        $stripe = new \Stripe\StripeClient(config('subscription.stripe_api_key'));

        // Bulk fetch all valid subscription IDs from Stripe (active + past_due)
        $validStripeIds = $this->fetchValidStripeSubscriptionIds($stripe, $onProgress);

        // Find DB subscriptions not in the valid set
        $staleSubscriptions = $subscriptions->filter(
            fn (Subscription $sub) => ! in_array($sub->stripe_subscription_id, $validStripeIds)
        );

        // For each stale subscription, get the exact Stripe status and check for resubscriptions
        $discrepancies = [];
        $resubscribed = [];
        $errors = [];

        foreach ($staleSubscriptions as $subscription) {
            try {
                $stripeSubscription = $stripe->subscriptions->retrieve(
                    $subscription->stripe_subscription_id
                );
                $stripeStatus = $stripeSubscription->status;

                usleep(100000); // 100ms rate limit delay
            } catch (\Exception $e) {
                $errors[] = [
                    'subscription_id' => $subscription->id,
                    'error' => $e->getMessage(),
                ];

                continue;
            }

            // Check if this user resubscribed under a different customer/subscription
            $activeSub = $this->findActiveSubscriptionByEmail($stripe, $stripeSubscription->customer);
            if ($activeSub) {
                $resubscribed[] = [
                    'subscription_id' => $subscription->id,
                    'team_id' => $subscription->team_id,
                    'email' => $activeSub['email'],
                    'old_stripe_subscription_id' => $subscription->stripe_subscription_id,
                    'old_stripe_customer_id' => $stripeSubscription->customer,
                    'new_stripe_subscription_id' => $activeSub['subscription_id'],
                    'new_stripe_customer_id' => $activeSub['customer_id'],
                    'new_status' => $activeSub['status'],
                ];

                continue;
            }

            $discrepancies[] = [
                'subscription_id' => $subscription->id,
                'team_id' => $subscription->team_id,
                'stripe_subscription_id' => $subscription->stripe_subscription_id,
                'stripe_status' => $stripeStatus,
            ];

            if ($this->fix) {
                $subscription->update([
                    'stripe_invoice_paid' => false,
                    'stripe_past_due' => false,
                ]);

                if ($stripeStatus === 'canceled') {
                    $subscription->team?->subscriptionEnded();
                }
            }
        }

        if ($this->fix && count($discrepancies) > 0) {
            send_internal_notification(
                'SyncStripeSubscriptionsJob: Fixed '.count($discrepancies)." discrepancies:\n".
                json_encode($discrepancies, JSON_PRETTY_PRINT)
            );
        }

        return [
            'total_checked' => $subscriptions->count(),
            'discrepancies' => $discrepancies,
            'resubscribed' => $resubscribed,
            'errors' => $errors,
            'fixed' => $this->fix,
        ];
    }

    /**
     * Given a Stripe customer ID, get their email and search for other customers
     * with the same email that have an active subscription.
     *
     * @return array{email: string, customer_id: string, subscription_id: string, status: string}|null
     */
    private function findActiveSubscriptionByEmail(\Stripe\StripeClient $stripe, string $customerId): ?array
    {
        try {
            $customer = $stripe->customers->retrieve($customerId);
            $email = $customer->email;

            if (! $email) {
                return null;
            }

            usleep(100000);

            $customers = $stripe->customers->all([
                'email' => $email,
                'limit' => 10,
            ]);

            usleep(100000);

            foreach ($customers->data as $matchingCustomer) {
                if ($matchingCustomer->id === $customerId) {
                    continue;
                }

                $subs = $stripe->subscriptions->all([
                    'customer' => $matchingCustomer->id,
                    'limit' => 10,
                ]);

                usleep(100000);

                foreach ($subs->data as $sub) {
                    if (in_array($sub->status, ['active', 'past_due'])) {
                        return [
                            'email' => $email,
                            'customer_id' => $matchingCustomer->id,
                            'subscription_id' => $sub->id,
                            'status' => $sub->status,
                        ];
                    }
                }
            }
        } catch (\Exception $e) {
            // Silently skip — will fall through to normal discrepancy
        }

        return null;
    }

    /**
     * Bulk fetch all active and past_due subscription IDs from Stripe.
     *
     * @return array<string>
     */
    private function fetchValidStripeSubscriptionIds(\Stripe\StripeClient $stripe, ?\Closure $onProgress = null): array
    {
        $validIds = [];
        $fetched = 0;

        foreach (['active', 'past_due'] as $status) {
            foreach ($stripe->subscriptions->all(['status' => $status, 'limit' => 100])->autoPagingIterator() as $sub) {
                $validIds[] = $sub->id;
                $fetched++;

                if ($onProgress) {
                    $onProgress($fetched);
                }
            }
        }

        return $validIds;
    }
}
