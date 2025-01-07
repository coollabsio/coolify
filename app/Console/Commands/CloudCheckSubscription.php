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
        foreach ($activeSubscribers as $team) {
            $stripeSubscriptionId = $team->subscription->stripe_subscription_id;
            $stripeInvoicePaid = $team->subscription->stripe_invoice_paid;
            $stripeCustomerId = $team->subscription->stripe_customer_id;
            if (! $stripeSubscriptionId) {
                echo "Team {$team->id} has no subscription, but invoice status is: {$stripeInvoicePaid}\n";
                echo "Link on Stripe: https://dashboard.stripe.com/customers/{$stripeCustomerId}\n";

                continue;
            }
            $subscription = $stripe->subscriptions->retrieve($stripeSubscriptionId);
            if ($subscription->status === 'active') {
                continue;
            }
            echo "Subscription {$stripeSubscriptionId} is not active ({$subscription->status})\n";
            echo "Link on Stripe: https://dashboard.stripe.com/subscriptions/{$stripeSubscriptionId}\n";
        }
    }
}
