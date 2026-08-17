<?php

namespace App\Actions\Stripe;

use App\Models\Subscription;
use Lorisleiva\Actions\Concerns\AsAction;
use Stripe\StripeClient;

class SyncStripeSubscriptions
{
    use AsAction;

    private const VALID_STRIPE_STATUSES = ['active', 'past_due'];

    public function handle(bool $fix = false, ?\Closure $onProgress = null): array
    {
        if (! isCloud() || ! isStripe()) {
            return ['error' => 'Not running on Cloud or Stripe not configured'];
        }

        $subscriptions = Subscription::whereNotNull('stripe_subscription_id')
            ->where('stripe_invoice_paid', true)
            ->get();

        $stripe = app()->bound(StripeClient::class)
            ? app(StripeClient::class)
            : new StripeClient(config('subscription.stripe_api_key'));

        // Bulk fetch all valid subscription IDs from Stripe (active + past_due)
        $validStripeIds = $this->fetchValidStripeSubscriptionIds($stripe, $onProgress);

        // Find DB subscriptions not in the valid set
        $staleSubscriptions = $subscriptions->filter(
            fn (Subscription $sub) => ! in_array($sub->stripe_subscription_id, $validStripeIds)
        );
        $staleSubscriptionCount = $staleSubscriptions->count();

        $onProgress?->__invoke('checking', 0, $staleSubscriptionCount);

        // For each stale subscription, get the exact Stripe status and check for resubscriptions
        $discrepancies = [];
        $resubscribed = [];
        $errors = [];
        $fixedCount = 0;
        $manualReviewCount = 0;

        foreach ($staleSubscriptions->values() as $index => $subscription) {
            $onProgress?->__invoke('checking', $index + 1, $staleSubscriptionCount);

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

            if (in_array($stripeStatus, self::VALID_STRIPE_STATUSES, true)) {
                continue;
            }

            $activeSub = $this->findActiveSubscriptionByEmail($stripe, $stripeSubscription->customer);
            $validReplacement = Subscription::query()
                ->where('team_id', $subscription->team_id)
                ->where('id', '!=', $subscription->id)
                ->where('stripe_invoice_paid', true)
                ->whereIn('stripe_subscription_id', $validStripeIds)
                ->first();

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
                    'linked_to_team' => $validReplacement?->stripe_subscription_id === $activeSub['subscription_id'],
                ];
            }

            $inactiveSubscription = null;
            if (! $validReplacement && ! $activeSub) {
                $inactiveSubscription = Subscription::query()
                    ->where('team_id', $subscription->team_id)
                    ->where('id', '!=', $subscription->id)
                    ->where('stripe_invoice_paid', false)
                    ->first();
            }

            $resolution = match (true) {
                (bool) $validReplacement => 'delete_stale',
                (bool) $activeSub => 'manual_review',
                (bool) $inactiveSubscription => 'delete_stale',
                default => 'end_subscription',
            };

            $discrepancies[] = [
                'subscription_id' => $subscription->id,
                'team_id' => $subscription->team_id,
                'stripe_subscription_id' => $subscription->stripe_subscription_id,
                'stripe_status' => $stripeStatus,
                'resolution' => $resolution,
            ];

            if ($fix) {
                $team = $subscription->team;

                if ($resolution === 'manual_review') {
                    $manualReviewCount++;

                    continue;
                }

                if ($resolution === 'delete_stale') {
                    if (! $validReplacement && $inactiveSubscription && $team) {
                        $team->subscriptionEnded($inactiveSubscription);
                    }

                    $subscription->delete();
                    $fixedCount++;

                    continue;
                }

                if ($team) {
                    $team->subscriptionEnded($subscription);
                } else {
                    $subscription->update([
                        'stripe_invoice_paid' => false,
                        'stripe_past_due' => false,
                    ]);
                }
                $fixedCount++;
            }
        }

        if ($fix && $fixedCount > 0) {
            send_internal_notification(
                "SyncStripeSubscriptions: Fixed {$fixedCount} discrepancies:\n".
                json_encode($discrepancies, JSON_PRETTY_PRINT)
            );
        }

        return [
            'total_checked' => $subscriptions->count(),
            'discrepancies' => $discrepancies,
            'resubscribed' => $resubscribed,
            'errors' => $errors,
            'fixed' => $fix,
            'fixed_count' => $fixedCount,
            'manual_review_count' => $manualReviewCount,
        ];
    }

    /**
     * Given a Stripe customer ID, get their email and search for other customers
     * with the same email that have an active subscription.
     *
     * @return array{email: string, customer_id: string, subscription_id: string, status: string}|null
     */
    private function findActiveSubscriptionByEmail(StripeClient $stripe, string $customerId): ?array
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
    private function fetchValidStripeSubscriptionIds(StripeClient $stripe, ?\Closure $onProgress = null): array
    {
        $validIds = [];
        $fetched = 0;

        foreach (self::VALID_STRIPE_STATUSES as $status) {
            foreach ($stripe->subscriptions->all(['status' => $status, 'limit' => 100])->autoPagingIterator() as $sub) {
                $validIds[] = $sub->id;
                $fetched++;

                if ($onProgress) {
                    $onProgress('fetching', $fetched, null);
                }
            }
        }

        return $validIds;
    }
}
