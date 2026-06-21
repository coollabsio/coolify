<?php

namespace App\Actions\Stripe;

use App\Models\Team;
use Stripe\StripeClient;

class CancelSubscriptionAtPeriodEnd
{
    private StripeClient $stripe;

    public function __construct(?StripeClient $stripe = null)
    {
        $this->stripe = $stripe ?? new StripeClient(config('subscription.stripe_api_key'));
    }

    /**
     * Cancel the team's subscription at the end of the current billing period.
     *
     * @return array{success: bool, error: string|null}
     */
    public function execute(Team $team): array
    {
        $subscription = $team->subscription;

        if (! $subscription?->stripe_subscription_id) {
            return ['success' => false, 'error' => 'No active subscription found.'];
        }

        if (! $subscription->stripe_invoice_paid) {
            return ['success' => false, 'error' => 'Subscription is not active.'];
        }

        if ($subscription->stripe_cancel_at_period_end) {
            return ['success' => false, 'error' => 'Subscription is already set to cancel at the end of the billing period.'];
        }

        try {
            $this->stripe->subscriptions->update($subscription->stripe_subscription_id, [
                'cancel_at_period_end' => true,
            ]);

            $subscription->update([
                'stripe_cancel_at_period_end' => true,
            ]);

            \Log::info("Subscription {$subscription->stripe_subscription_id} set to cancel at period end for team {$team->name}");

            return ['success' => true, 'error' => null];
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            \Log::error("Stripe cancel at period end error for team {$team->id}: ".$e->getMessage());

            return ['success' => false, 'error' => 'Stripe error: '.$e->getMessage()];
        } catch (\Exception $e) {
            \Log::error("Cancel at period end error for team {$team->id}: ".$e->getMessage());

            return ['success' => false, 'error' => 'An unexpected error occurred. Please contact support.'];
        }
    }
}
