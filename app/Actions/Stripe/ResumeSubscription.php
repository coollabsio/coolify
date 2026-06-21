<?php

namespace App\Actions\Stripe;

use App\Models\Team;
use Stripe\StripeClient;

class ResumeSubscription
{
    private StripeClient $stripe;

    public function __construct(?StripeClient $stripe = null)
    {
        $this->stripe = $stripe ?? new StripeClient(config('subscription.stripe_api_key'));
    }

    /**
     * Resume a subscription that was set to cancel at the end of the billing period.
     *
     * @return array{success: bool, error: string|null}
     */
    public function execute(Team $team): array
    {
        $subscription = $team->subscription;

        if (! $subscription?->stripe_subscription_id) {
            return ['success' => false, 'error' => 'No active subscription found.'];
        }

        if (! $subscription->stripe_cancel_at_period_end) {
            return ['success' => false, 'error' => 'Subscription is not set to cancel.'];
        }

        try {
            $this->stripe->subscriptions->update($subscription->stripe_subscription_id, [
                'cancel_at_period_end' => false,
            ]);

            $subscription->update([
                'stripe_cancel_at_period_end' => false,
            ]);

            \Log::info("Subscription {$subscription->stripe_subscription_id} resumed for team {$team->name}");

            return ['success' => true, 'error' => null];
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            \Log::error("Stripe resume subscription error for team {$team->id}: ".$e->getMessage());

            return ['success' => false, 'error' => 'Stripe error: '.$e->getMessage()];
        } catch (\Exception $e) {
            \Log::error("Resume subscription error for team {$team->id}: ".$e->getMessage());

            return ['success' => false, 'error' => 'An unexpected error occurred. Please contact support.'];
        }
    }
}
