<?php

namespace App\Console\Commands\Cloud;

use App\Jobs\SyncStripeSubscriptionsJob;
use Illuminate\Console\Command;

class SyncStripeSubscriptions extends Command
{
    protected $signature = 'cloud:sync-stripe-subscriptions {--fix : Actually fix discrepancies (default is check only)}';

    protected $description = 'Sync subscription status with Stripe. By default only checks, use --fix to apply changes.';

    public function handle(): int
    {
        if (! isCloud()) {
            $this->error('This command can only be run on Coolify Cloud.');

            return 1;
        }

        if (! isStripe()) {
            $this->error('Stripe is not configured.');

            return 1;
        }

        $fix = $this->option('fix');

        if ($fix) {
            $this->warn('Running with --fix: discrepancies will be corrected.');
        } else {
            $this->info('Running in check mode (no changes will be made). Use --fix to apply corrections.');
        }

        $this->newLine();

        $job = new SyncStripeSubscriptionsJob($fix);
        $fetched = 0;
        $result = $job->handle(function (int $count) use (&$fetched): void {
            $fetched = $count;
            $this->output->write("\r  Fetching subscriptions from Stripe... {$fetched}");
        });
        if ($fetched > 0) {
            $this->output->write("\r".str_repeat(' ', 60)."\r");
        }

        if (isset($result['error'])) {
            $this->error($result['error']);

            return 1;
        }

        $this->info("Total subscriptions checked: {$result['total_checked']}");
        $this->newLine();

        if (count($result['discrepancies']) > 0) {
            $this->warn('Discrepancies found: '.count($result['discrepancies']));
            $this->newLine();

            foreach ($result['discrepancies'] as $discrepancy) {
                $this->line("  - Subscription ID: {$discrepancy['subscription_id']}");
                $this->line("    Team ID: {$discrepancy['team_id']}");
                $this->line("    Stripe ID: {$discrepancy['stripe_subscription_id']}");
                $this->line("    Stripe Status: {$discrepancy['stripe_status']}");
                $this->newLine();
            }

            if ($fix) {
                $this->info('All discrepancies have been fixed.');
            } else {
                $this->comment('Run with --fix to correct these discrepancies.');
            }
        } else {
            $this->info('No discrepancies found. All subscriptions are in sync.');
        }

        if (count($result['resubscribed']) > 0) {
            $this->newLine();
            $this->warn('Resubscribed users (same email, different customer): '.count($result['resubscribed']));
            $this->newLine();

            foreach ($result['resubscribed'] as $resub) {
                $this->line("  - Team ID: {$resub['team_id']} | Email: {$resub['email']}");
                $this->line("    Old: {$resub['old_stripe_subscription_id']} (cus: {$resub['old_stripe_customer_id']})");
                $this->line("    New: {$resub['new_stripe_subscription_id']} (cus: {$resub['new_stripe_customer_id']}) [{$resub['new_status']}]");
                $this->newLine();
            }
        }

        if (count($result['errors']) > 0) {
            $this->newLine();
            $this->error('Errors encountered: '.count($result['errors']));
            foreach ($result['errors'] as $error) {
                $this->line("  - Subscription {$error['subscription_id']}: {$error['error']}");
            }
        }

        return 0;
    }
}
