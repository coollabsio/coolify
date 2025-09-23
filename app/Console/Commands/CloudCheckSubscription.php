<?php

namespace App\Console\Commands;

use App\Models\Team;
use Illuminate\Console\Command;

class CloudCheckSubscription extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cloud:check-subscription';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check Cloud subscriptions';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $stripe = new \Stripe\StripeClient(config('subscription.stripe_api_key'));
        $activeSubscribers = Team::whereRelation('subscription', 'stripe_invoice_paid', true)->get();

        $out = fopen('php://output', 'w');
        // CSV header
        fputcsv($out, [
            'team_id',
            'invoice_status',
            'stripe_customer_url',
            'stripe_subscription_id',
            'subscription_status',
            'subscription_url',
            'note',
        ]);

        foreach ($activeSubscribers as $team) {
            $stripeSubscriptionId = $team->subscription->stripe_subscription_id;
            $stripeInvoicePaid = $team->subscription->stripe_invoice_paid;
            $stripeCustomerId = $team->subscription->stripe_customer_id;

            if (! $stripeSubscriptionId && str($stripeInvoicePaid)->lower() != 'past_due') {
                fputcsv($out, [
                    $team->id,
                    $stripeInvoicePaid,
                    $stripeCustomerId ? "https://dashboard.stripe.com/customers/{$stripeCustomerId}" : null,
                    null,
                    null,
                    null,
                    'Missing subscription ID while invoice not past_due',
                ]);

                continue;
            }

            if (! $stripeSubscriptionId) {
                // No subscription ID and invoice is past_due, still record for visibility
                fputcsv($out, [
                    $team->id,
                    $stripeInvoicePaid,
                    $stripeCustomerId ? "https://dashboard.stripe.com/customers/{$stripeCustomerId}" : null,
                    null,
                    null,
                    null,
                    'Missing subscription ID',
                ]);

                continue;
            }

            $subscription = $stripe->subscriptions->retrieve($stripeSubscriptionId);
            if ($subscription->status === 'active') {
                continue;
            }

            fputcsv($out, [
                $team->id,
                $stripeInvoicePaid,
                $stripeCustomerId ? "https://dashboard.stripe.com/customers/{$stripeCustomerId}" : null,
                $stripeSubscriptionId,
                $subscription->status,
                "https://dashboard.stripe.com/subscriptions/{$stripeSubscriptionId}",
                'Subscription not active',
            ]);
        }

        fclose($out);
    }
}
