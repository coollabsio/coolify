<?php

namespace App\Actions\Stripe;

use App\Models\Team;
use Stripe\StripeClient;

class RefundSubscription
{
    private StripeClient $stripe;

    private const REFUND_WINDOW_DAYS = 30;

    public function __construct(?StripeClient $stripe = null)
    {
        $this->stripe = $stripe ?? new StripeClient(config('subscription.stripe_api_key'));
    }

    /**
     * Check if the team's subscription is eligible for a refund.
     *
     * @return array{eligible: bool, days_remaining: int, reason: string}
     */
    public function checkEligibility(Team $team): array
    {
        $subscription = $team->subscription;

        if ($subscription?->stripe_refunded_at) {
            return $this->ineligible('A refund has already been processed for this team.');
        }

        if (! $subscription?->stripe_subscription_id) {
            return $this->ineligible('No active subscription found.');
        }

        if (! $subscription->stripe_invoice_paid) {
            return $this->ineligible('Subscription invoice is not paid.');
        }

        try {
            $stripeSubscription = $this->stripe->subscriptions->retrieve($subscription->stripe_subscription_id);
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            return $this->ineligible('Subscription not found in Stripe.');
        }

        if (! in_array($stripeSubscription->status, ['active', 'trialing'])) {
            return $this->ineligible("Subscription status is '{$stripeSubscription->status}'.");
        }

        $startDate = \Carbon\Carbon::createFromTimestamp($stripeSubscription->start_date);
        $daysSinceStart = (int) $startDate->diffInDays(now());
        $daysRemaining = self::REFUND_WINDOW_DAYS - $daysSinceStart;

        if ($daysRemaining <= 0) {
            return $this->ineligible('The 30-day refund window has expired.');
        }

        return [
            'eligible' => true,
            'days_remaining' => $daysRemaining,
            'reason' => 'Eligible for refund.',
        ];
    }

    /**
     * Process a full refund and cancel the subscription.
     *
     * @return array{success: bool, error: string|null}
     */
    public function execute(Team $team): array
    {
        $eligibility = $this->checkEligibility($team);

        if (! $eligibility['eligible']) {
            return ['success' => false, 'error' => $eligibility['reason']];
        }

        $subscription = $team->subscription;

        try {
            $invoices = $this->stripe->invoices->all([
                'subscription' => $subscription->stripe_subscription_id,
                'status' => 'paid',
                'limit' => 1,
            ]);

            if (empty($invoices->data)) {
                return ['success' => false, 'error' => 'No paid invoice found to refund.'];
            }

            $invoice = $invoices->data[0];
            $paymentIntentId = $invoice->payment_intent;

            if (! $paymentIntentId) {
                return ['success' => false, 'error' => 'No payment intent found on the invoice.'];
            }

            $this->stripe->refunds->create([
                'payment_intent' => $paymentIntentId,
            ]);

            $this->stripe->subscriptions->cancel($subscription->stripe_subscription_id);

            $subscription->update([
                'stripe_cancel_at_period_end' => false,
                'stripe_invoice_paid' => false,
                'stripe_trial_already_ended' => false,
                'stripe_past_due' => false,
                'stripe_feedback' => 'Refund requested by user',
                'stripe_comment' => 'Full refund processed within 30-day window at '.now()->toDateTimeString(),
                'stripe_refunded_at' => now(),
            ]);

            $team->subscriptionEnded();

            \Log::info("Refunded and cancelled subscription {$subscription->stripe_subscription_id} for team {$team->name}");

            return ['success' => true, 'error' => null];
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            \Log::error("Stripe refund error for team {$team->id}: ".$e->getMessage());

            return ['success' => false, 'error' => 'Stripe error: '.$e->getMessage()];
        } catch (\Exception $e) {
            \Log::error("Refund error for team {$team->id}: ".$e->getMessage());

            return ['success' => false, 'error' => 'An unexpected error occurred. Please contact support.'];
        }
    }

    /**
     * @return array{eligible: bool, days_remaining: int, reason: string}
     */
    private function ineligible(string $reason): array
    {
        return [
            'eligible' => false,
            'days_remaining' => 0,
            'reason' => $reason,
        ];
    }
}
