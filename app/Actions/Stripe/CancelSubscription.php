<?php

namespace App\Actions\Stripe;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Collection;
use Stripe\StripeClient;

class CancelSubscription
{
    private User $user;

    private bool $isDryRun;

    private ?StripeClient $stripe = null;

    public function __construct(User $user, bool $isDryRun = false)
    {
        $this->user = $user;
        $this->isDryRun = $isDryRun;

        if (! $isDryRun && isCloud()) {
            $this->stripe = new StripeClient(config('subscription.stripe_api_key'));
        }
    }

    public function getSubscriptionsPreview(): Collection
    {
        $subscriptions = collect();

        // Get all teams the user belongs to
        $teams = $this->user->teams()->get();

        foreach ($teams as $team) {
            // Only include subscriptions from teams where user is owner
            $userRole = $team->pivot->role;
            if ($userRole === 'owner' && $team->subscription) {
                $subscription = $team->subscription;

                // Only include active subscriptions
                if ($subscription->stripe_subscription_id &&
                    $subscription->stripe_invoice_paid) {
                    $subscriptions->push($subscription);
                }
            }
        }

        return $subscriptions;
    }

    /**
     * Verify subscriptions exist and are active in Stripe API
     *
     * @return array ['verified' => Collection, 'not_found' => Collection, 'errors' => array]
     */
    public function verifySubscriptionsInStripe(): array
    {
        if (! isCloud()) {
            return [
                'verified' => collect(),
                'not_found' => collect(),
                'errors' => [],
            ];
        }

        $stripe = new StripeClient(config('subscription.stripe_api_key'));
        $subscriptions = $this->getSubscriptionsPreview();

        $verified = collect();
        $notFound = collect();
        $errors = [];

        foreach ($subscriptions as $subscription) {
            try {
                $stripeSubscription = $stripe->subscriptions->retrieve($subscription->stripe_subscription_id);

                // Check if subscription is actually active in Stripe
                if (in_array($stripeSubscription->status, ['active', 'trialing', 'past_due'])) {
                    $verified->push([
                        'subscription' => $subscription,
                        'stripe_status' => $stripeSubscription->status,
                        'current_period_end' => $stripeSubscription->current_period_end,
                    ]);
                } else {
                    $notFound->push([
                        'subscription' => $subscription,
                        'reason' => "Status in Stripe: {$stripeSubscription->status}",
                    ]);
                }
            } catch (\Stripe\Exception\InvalidRequestException $e) {
                // Subscription doesn't exist in Stripe
                $notFound->push([
                    'subscription' => $subscription,
                    'reason' => 'Not found in Stripe',
                ]);
            } catch (\Exception $e) {
                $errors[] = "Error verifying subscription {$subscription->stripe_subscription_id}: ".$e->getMessage();
                \Log::error("Error verifying subscription {$subscription->stripe_subscription_id}: ".$e->getMessage());
            }
        }

        return [
            'verified' => $verified,
            'not_found' => $notFound,
            'errors' => $errors,
        ];
    }

    public function execute(): array
    {
        if ($this->isDryRun) {
            return [
                'cancelled' => 0,
                'failed' => 0,
                'errors' => [],
            ];
        }

        $cancelledCount = 0;
        $failedCount = 0;
        $errors = [];

        $subscriptions = $this->getSubscriptionsPreview();

        foreach ($subscriptions as $subscription) {
            try {
                $this->cancelSingleSubscription($subscription);
                $cancelledCount++;
            } catch (\Exception $e) {
                $failedCount++;
                $errorMessage = "Failed to cancel subscription {$subscription->stripe_subscription_id}: ".$e->getMessage();
                $errors[] = $errorMessage;
                \Log::error($errorMessage);
            }
        }

        return [
            'cancelled' => $cancelledCount,
            'failed' => $failedCount,
            'errors' => $errors,
        ];
    }

    private function cancelSingleSubscription(Subscription $subscription): void
    {
        if (! $this->stripe) {
            throw new \Exception('Stripe client not initialized');
        }

        $subscriptionId = $subscription->stripe_subscription_id;

        // Cancel the subscription immediately (not at period end)
        $this->stripe->subscriptions->cancel($subscriptionId, []);

        // Update local database
        $subscription->update([
            'stripe_cancel_at_period_end' => false,
            'stripe_invoice_paid' => false,
            'stripe_trial_already_ended' => false,
            'stripe_past_due' => false,
            'stripe_feedback' => 'User account deleted',
            'stripe_comment' => 'Subscription cancelled due to user account deletion at '.now()->toDateTimeString(),
        ]);

        // Call the team's subscription ended method to handle cleanup
        if ($subscription->team) {
            $subscription->team->subscriptionEnded();
        }

        \Log::info("Cancelled Stripe subscription: {$subscriptionId} for team: {$subscription->team->name}");
    }

    /**
     * Cancel a single subscription by ID (helper method for external use)
     */
    public static function cancelById(string $subscriptionId): bool
    {
        try {
            if (! isCloud()) {
                return false;
            }

            $stripe = new StripeClient(config('subscription.stripe_api_key'));
            $stripe->subscriptions->cancel($subscriptionId, []);

            // Update local record if exists
            $subscription = Subscription::where('stripe_subscription_id', $subscriptionId)->first();
            if ($subscription) {
                $subscription->update([
                    'stripe_cancel_at_period_end' => false,
                    'stripe_invoice_paid' => false,
                    'stripe_trial_already_ended' => false,
                    'stripe_past_due' => false,
                ]);

                if ($subscription->team) {
                    $subscription->team->subscriptionEnded();
                }
            }

            return true;
        } catch (\Exception $e) {
            \Log::error("Failed to cancel subscription {$subscriptionId}: ".$e->getMessage());

            return false;
        }
    }
}
