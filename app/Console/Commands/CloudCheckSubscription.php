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
    protected $signature = 'cloud:check-subscription
                            {--fix : Fix canceled subscriptions in database}
                            {--dry-run : Show what would be fixed without making changes}
                            {--one : Only check/fix the first found subscription}';

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

        if ($this->option('fix') || $this->option('dry-run')) {
            return $this->fixCanceledSubscriptions($stripe);
        }

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

    /**
     * Fix canceled subscriptions in the database
     */
    private function fixCanceledSubscriptions(\Stripe\StripeClient $stripe)
    {
        $isDryRun = $this->option('dry-run');
        $checkOne = $this->option('one');

        if ($isDryRun) {
            $this->info('DRY RUN MODE - No changes will be made');
            if ($checkOne) {
                $this->info('Checking only the first canceled subscription...');
            } else {
                $this->info('Checking for canceled subscriptions...');
            }
        } else {
            if ($checkOne) {
                $this->info('Checking and fixing only the first canceled subscription...');
            } else {
                $this->info('Checking and fixing canceled subscriptions...');
            }
        }

        $teamsWithSubscriptions = Team::whereRelation('subscription', 'stripe_invoice_paid', true)->get();
        $toFixCount = 0;
        $fixedCount = 0;
        $errors = [];
        $canceledSubscriptions = [];

        foreach ($teamsWithSubscriptions as $team) {
            $subscription = $team->subscription;

            if (! $subscription->stripe_subscription_id) {
                continue;
            }

            try {
                $stripeSubscription = $stripe->subscriptions->retrieve(
                    $subscription->stripe_subscription_id
                );

                if ($stripeSubscription->status === 'canceled') {
                    $toFixCount++;

                    // Get team members' emails
                    $memberEmails = $team->members->pluck('email')->toArray();

                    $canceledSubscriptions[] = [
                        'team_id' => $team->id,
                        'team_name' => $team->name,
                        'customer_id' => $subscription->stripe_customer_id,
                        'subscription_id' => $subscription->stripe_subscription_id,
                        'status' => 'canceled',
                        'member_emails' => $memberEmails,
                        'subscription_model' => $subscription->toArray(),
                    ];

                    if ($isDryRun) {
                        $this->warn('Would fix canceled subscription:');
                        $this->line("  Team ID: {$team->id}");
                        $this->line("  Team Name: {$team->name}");
                        $this->line('  Team Members: '.implode(', ', $memberEmails));
                        $this->line("  Customer URL: https://dashboard.stripe.com/customers/{$subscription->stripe_customer_id}");
                        $this->line("  Subscription URL: https://dashboard.stripe.com/subscriptions/{$subscription->stripe_subscription_id}");
                        $this->line('  Current Subscription Data:');
                        foreach ($subscription->getAttributes() as $key => $value) {
                            if (is_null($value)) {
                                $this->line("    - {$key}: null");
                            } elseif (is_bool($value)) {
                                $this->line("    - {$key}: ".($value ? 'true' : 'false'));
                            } else {
                                $this->line("    - {$key}: {$value}");
                            }
                        }
                        $this->newLine();
                    } else {
                        $this->warn("Found canceled subscription for Team ID: {$team->id}");

                        // Send internal notification with all details before fixing
                        $notificationMessage = "Fixing canceled subscription:\n";
                        $notificationMessage .= "Team ID: {$team->id}\n";
                        $notificationMessage .= "Team Name: {$team->name}\n";
                        $notificationMessage .= 'Team Members: '.implode(', ', $memberEmails)."\n";
                        $notificationMessage .= "Customer URL: https://dashboard.stripe.com/customers/{$subscription->stripe_customer_id}\n";
                        $notificationMessage .= "Subscription URL: https://dashboard.stripe.com/subscriptions/{$subscription->stripe_subscription_id}\n";
                        $notificationMessage .= "Subscription Data:\n";
                        foreach ($subscription->getAttributes() as $key => $value) {
                            if (is_null($value)) {
                                $notificationMessage .= "  - {$key}: null\n";
                            } elseif (is_bool($value)) {
                                $notificationMessage .= "  - {$key}: ".($value ? 'true' : 'false')."\n";
                            } else {
                                $notificationMessage .= "  - {$key}: {$value}\n";
                            }
                        }
                        send_internal_notification($notificationMessage);

                        // Apply the same logic as customer.subscription.deleted webhook
                        $team->subscriptionEnded();

                        $fixedCount++;
                        $this->info("  ✓ Fixed subscription for Team ID: {$team->id}");
                        $this->line('    Team Members: '.implode(', ', $memberEmails));
                        $this->line("    Customer URL: https://dashboard.stripe.com/customers/{$subscription->stripe_customer_id}");
                        $this->line("    Subscription URL: https://dashboard.stripe.com/subscriptions/{$subscription->stripe_subscription_id}");
                    }

                    // Break if --one flag is set
                    if ($checkOne) {
                        break;
                    }
                }
            } catch (\Stripe\Exception\InvalidRequestException $e) {
                if ($e->getStripeCode() === 'resource_missing') {
                    $toFixCount++;

                    // Get team members' emails
                    $memberEmails = $team->members->pluck('email')->toArray();

                    $canceledSubscriptions[] = [
                        'team_id' => $team->id,
                        'team_name' => $team->name,
                        'customer_id' => $subscription->stripe_customer_id,
                        'subscription_id' => $subscription->stripe_subscription_id,
                        'status' => 'missing',
                        'member_emails' => $memberEmails,
                        'subscription_model' => $subscription->toArray(),
                    ];

                    if ($isDryRun) {
                        $this->error('Would fix missing subscription (not found in Stripe):');
                        $this->line("  Team ID: {$team->id}");
                        $this->line("  Team Name: {$team->name}");
                        $this->line('  Team Members: '.implode(', ', $memberEmails));
                        $this->line("  Customer URL: https://dashboard.stripe.com/customers/{$subscription->stripe_customer_id}");
                        $this->line("  Subscription ID (missing): {$subscription->stripe_subscription_id}");
                        $this->line('  Current Subscription Data:');
                        foreach ($subscription->getAttributes() as $key => $value) {
                            if (is_null($value)) {
                                $this->line("    - {$key}: null");
                            } elseif (is_bool($value)) {
                                $this->line("    - {$key}: ".($value ? 'true' : 'false'));
                            } else {
                                $this->line("    - {$key}: {$value}");
                            }
                        }
                        $this->newLine();
                    } else {
                        $this->error("Subscription not found in Stripe for Team ID: {$team->id}");

                        // Send internal notification with all details before fixing
                        $notificationMessage = "Fixing missing subscription (not found in Stripe):\n";
                        $notificationMessage .= "Team ID: {$team->id}\n";
                        $notificationMessage .= "Team Name: {$team->name}\n";
                        $notificationMessage .= 'Team Members: '.implode(', ', $memberEmails)."\n";
                        $notificationMessage .= "Customer URL: https://dashboard.stripe.com/customers/{$subscription->stripe_customer_id}\n";
                        $notificationMessage .= "Subscription ID (missing): {$subscription->stripe_subscription_id}\n";
                        $notificationMessage .= "Subscription Data:\n";
                        foreach ($subscription->getAttributes() as $key => $value) {
                            if (is_null($value)) {
                                $notificationMessage .= "  - {$key}: null\n";
                            } elseif (is_bool($value)) {
                                $notificationMessage .= "  - {$key}: ".($value ? 'true' : 'false')."\n";
                            } else {
                                $notificationMessage .= "  - {$key}: {$value}\n";
                            }
                        }
                        send_internal_notification($notificationMessage);

                        // Apply the same logic as customer.subscription.deleted webhook
                        $team->subscriptionEnded();

                        $fixedCount++;
                        $this->info("  ✓ Fixed missing subscription for Team ID: {$team->id}");
                        $this->line('    Team Members: '.implode(', ', $memberEmails));
                        $this->line("    Customer URL: https://dashboard.stripe.com/customers/{$subscription->stripe_customer_id}");
                    }

                    // Break if --one flag is set
                    if ($checkOne) {
                        break;
                    }
                } else {
                    $errors[] = "Team ID {$team->id}: ".$e->getMessage();
                }
            } catch (\Exception $e) {
                $errors[] = "Team ID {$team->id}: ".$e->getMessage();
            }
        }

        $this->newLine();
        $this->info('Summary:');

        if ($isDryRun) {
            $this->info("  - Found {$toFixCount} canceled/missing subscriptions that would be fixed");

            if ($toFixCount > 0) {
                $this->newLine();
                $this->comment('Run with --fix to apply these changes');
            }
        } else {
            $this->info("  - Fixed {$fixedCount} canceled/missing subscriptions");
        }

        if (! empty($errors)) {
            $this->newLine();
            $this->error('Errors encountered:');
            foreach ($errors as $error) {
                $this->error("  - {$error}");
            }
        }

        return 0;
    }
}
