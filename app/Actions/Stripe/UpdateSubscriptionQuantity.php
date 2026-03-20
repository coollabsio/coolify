<?php

namespace App\Actions\Stripe;

use App\Jobs\ServerLimitCheckJob;
use App\Models\Team;
use Stripe\StripeClient;

class UpdateSubscriptionQuantity
{
    public const int MAX_SERVER_LIMIT = 100;

    public const int MIN_SERVER_LIMIT = 2;

    private StripeClient $stripe;

    public function __construct(?StripeClient $stripe = null)
    {
        $this->stripe = $stripe ?? new StripeClient(config('subscription.stripe_api_key'));
    }

    /**
     * Fetch a full price preview for a quantity change from Stripe.
     * Returns both the prorated amount due now and the recurring cost for the next billing cycle.
     *
     * @return array{success: bool, error: string|null, preview: array{due_now: int, recurring_subtotal: int, recurring_tax: int, recurring_total: int, unit_price: int, tax_description: string|null, quantity: int, currency: string}|null}
     */
    public function fetchPricePreview(Team $team, int $quantity): array
    {
        $subscription = $team->subscription;

        if (! $subscription?->stripe_subscription_id || ! $subscription->stripe_invoice_paid) {
            return ['success' => false, 'error' => 'No active subscription found.', 'preview' => null];
        }

        try {
            $stripeSubscription = $this->stripe->subscriptions->retrieve($subscription->stripe_subscription_id);
            $item = $stripeSubscription->items->data[0] ?? null;

            if (! $item) {
                return ['success' => false, 'error' => 'Could not retrieve subscription details.', 'preview' => null];
            }

            $currency = strtoupper($item->price->currency ?? 'usd');

            // Upcoming invoice gives us the prorated amount due now
            $upcomingInvoice = $this->stripe->invoices->upcoming([
                'customer' => $subscription->stripe_customer_id,
                'subscription' => $subscription->stripe_subscription_id,
                'subscription_items' => [
                    ['id' => $item->id, 'quantity' => $quantity],
                ],
                'subscription_proration_behavior' => 'create_prorations',
            ]);

            // Extract tax percentage — try total_tax_amounts first, fall back to invoice tax/subtotal
            $taxPercentage = 0.0;
            $taxDescription = null;
            if (! empty($upcomingInvoice->total_tax_amounts)) {
                $taxAmount = $upcomingInvoice->total_tax_amounts[0] ?? null;
                if ($taxAmount?->tax_rate) {
                    $taxRate = $this->stripe->taxRates->retrieve($taxAmount->tax_rate);
                    $taxPercentage = (float) ($taxRate->percentage ?? 0);
                    $taxDescription = $taxRate->display_name.' ('.$taxRate->jurisdiction.') '.$taxRate->percentage.'%';
                }
            }
            // Fallback tax percentage from invoice totals - use tax_rate details when available for accuracy
            if ($taxPercentage === 0.0 && ($upcomingInvoice->tax ?? 0) > 0 && ($upcomingInvoice->subtotal ?? 0) > 0) {
                $taxPercentage = round(($upcomingInvoice->tax / $upcomingInvoice->subtotal) * 100, 2);
            }

            // Recurring cost for next cycle — read from non-proration invoice lines
            $recurringSubtotal = 0;
            foreach ($upcomingInvoice->lines->data as $line) {
                if (! $line->proration) {
                    $recurringSubtotal += $line->amount;
                }
            }
            $unitPrice = $quantity > 0 ? (int) round($recurringSubtotal / $quantity) : 0;

            $recurringTax = $taxPercentage > 0
                ? (int) round($recurringSubtotal * $taxPercentage / 100)
                : 0;
            $recurringTotal = $recurringSubtotal + $recurringTax;

            // Due now = amount_due (accounts for customer balance/credits) minus recurring
            $amountDue = $upcomingInvoice->amount_due ?? $upcomingInvoice->total ?? 0;
            $dueNow = $amountDue - $recurringTotal;

            return [
                'success' => true,
                'error' => null,
                'preview' => [
                    'due_now' => $dueNow,
                    'recurring_subtotal' => $recurringSubtotal,
                    'recurring_tax' => $recurringTax,
                    'recurring_total' => $recurringTotal,
                    'unit_price' => $unitPrice,
                    'tax_description' => $taxDescription,
                    'quantity' => $quantity,
                    'currency' => $currency,
                ],
            ];
        } catch (\Exception $e) {
            \Log::warning("Stripe fetch price preview error for team {$team->id}: ".$e->getMessage());

            return ['success' => false, 'error' => 'Could not load price preview.', 'preview' => null];
        }
    }

    /**
     * Update the subscription quantity (server limit) for a team.
     *
     * @return array{success: bool, error: string|null}
     */
    public function execute(Team $team, int $quantity): array
    {
        if ($quantity < self::MIN_SERVER_LIMIT) {
            return ['success' => false, 'error' => 'Minimum server limit is '.self::MIN_SERVER_LIMIT.'.'];
        }

        $subscription = $team->subscription;

        if (! $subscription?->stripe_subscription_id) {
            return ['success' => false, 'error' => 'No active subscription found.'];
        }

        if (! $subscription->stripe_invoice_paid) {
            return ['success' => false, 'error' => 'Subscription is not active.'];
        }

        try {
            $stripeSubscription = $this->stripe->subscriptions->retrieve($subscription->stripe_subscription_id);
            $item = $stripeSubscription->items->data[0] ?? null;

            if (! $item?->id) {
                return ['success' => false, 'error' => 'Could not find subscription item.'];
            }

            $previousQuantity = $item->quantity ?? $team->custom_server_limit;

            $updatedSubscription = $this->stripe->subscriptions->update($subscription->stripe_subscription_id, [
                'items' => [
                    ['id' => $item->id, 'quantity' => $quantity],
                ],
                'proration_behavior' => 'always_invoice',
                'expand' => ['latest_invoice'],
            ]);

            // Check if the proration invoice was paid
            $latestInvoice = $updatedSubscription->latest_invoice;
            if ($latestInvoice && $latestInvoice->status !== 'paid') {
                \Log::warning("Subscription {$subscription->stripe_subscription_id} quantity updated but invoice not paid (status: {$latestInvoice->status}) for team {$team->name}. Reverting to {$previousQuantity}.");

                // Revert subscription quantity on Stripe
                try {
                    $this->stripe->subscriptions->update($subscription->stripe_subscription_id, [
                        'items' => [
                            ['id' => $item->id, 'quantity' => $previousQuantity],
                        ],
                        'proration_behavior' => 'none',
                    ]);
                } catch (\Exception $revertException) {
                    \Log::critical("Failed to revert Stripe quantity for subscription {$subscription->stripe_subscription_id}, team {$team->id}. Stripe may have quantity {$quantity} but local is {$previousQuantity}. Error: ".$revertException->getMessage());
                    send_internal_notification(
                        "CRITICAL: Stripe quantity revert failed for subscription {$subscription->stripe_subscription_id}, team {$team->id}. Manual reconciliation required."
                    );
                }

                // Void the unpaid invoice
                if ($latestInvoice->id) {
                    $this->stripe->invoices->voidInvoice($latestInvoice->id);
                }

                return ['success' => false, 'error' => 'Payment failed. Your server limit was not changed. Please check your payment method and try again.'];
            }

            $team->update([
                'custom_server_limit' => $quantity,
            ]);

            ServerLimitCheckJob::dispatch($team);

            \Log::info("Subscription {$subscription->stripe_subscription_id} quantity updated to {$quantity} for team {$team->name}");

            return ['success' => true, 'error' => null];
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            \Log::error("Stripe update quantity error for team {$team->id}: ".$e->getMessage());

            return ['success' => false, 'error' => 'Stripe error: '.$e->getMessage()];
        } catch (\Exception $e) {
            \Log::error("Update subscription quantity error for team {$team->id}: ".$e->getMessage());

            return ['success' => false, 'error' => 'An unexpected error occurred. Please contact support.'];
        }
    }

    private function formatAmount(int $cents, string $currency): string
    {
        return strtoupper($currency) === 'USD'
            ? '$'.number_format($cents / 100, 2)
            : number_format($cents / 100, 2).' '.$currency;
    }
}
