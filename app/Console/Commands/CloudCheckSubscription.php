<?php

namespace App\Console\Commands;

use App\Models\Team;
use Illuminate\Console\Command;
use Stripe\StripeClient;

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
        $stripeClient = new StripeClient(config('subscription.stripe_api_key'));
        $activeSubscribers = Team::query()->whereRelation('subscription', 'stripe_invoice_paid', true)->get();
        foreach ($activeSubscribers as $activeSubscriber) {
            $stripeSubscriptionId = $activeSubscriber->subscription->stripe_subscription_id;
            $stripeInvoicePaid = $activeSubscriber->subscription->stripe_invoice_paid;
            $stripeCustomerId = $activeSubscriber->subscription->stripe_customer_id;
            if (! $stripeSubscriptionId) {
                echo "Team {$activeSubscriber->id} has no subscription, but invoice status is: {$stripeInvoicePaid}\n";
                echo "Link on Stripe: https://dashboard.stripe.com/customers/{$stripeCustomerId}\n";

                continue;
            }
            $subscription = $stripeClient->subscriptions->retrieve($stripeSubscriptionId);
            if ($subscription->status === 'active') {
                continue;
            }
            echo "Subscription {$stripeSubscriptionId} is not active ({$subscription->status})\n";
            echo "Link on Stripe: https://dashboard.stripe.com/subscriptions/{$stripeSubscriptionId}\n";
        }
    }
}
