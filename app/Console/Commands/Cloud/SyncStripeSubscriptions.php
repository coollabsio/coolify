<?php

namespace App\Console\Commands\Cloud;

use App\Actions\Stripe\SyncStripeSubscriptions as SyncStripeSubscriptionsAction;
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

        $progressShown = false;
        $result = SyncStripeSubscriptionsAction::run($fix, function (string $stage, int $current, ?int $total) use (&$progressShown): void {
            $progressShown = true;
            $message = match ($stage) {
                'checking' => "  Checking stale subscriptions against Stripe... {$current}/{$total}",
                default => "  Fetching valid subscriptions from Stripe... {$current}",
            };

            $this->output->write("\r".str_pad($message, 80));
        });
        if ($progressShown) {
            $this->output->write("\r".str_repeat(' ', 80)."\r");
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
                $resolution = match ($discrepancy['resolution']) {
                    'delete_stale' => 'Delete stale local row',
                    'manual_review' => 'Manual review required',
                    default => 'End local subscription',
                };
                $this->line("    Resolution: {$resolution}");
                $this->newLine();
            }

            if ($fix) {
                $this->info("Automatic corrections applied: {$result['fixed_count']}");
                if ($result['manual_review_count'] > 0) {
                    $this->warn("Skipped for manual review: {$result['manual_review_count']}");
                }
            } else {
                $this->comment('Run with --fix to apply automatic corrections.');
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
                $this->line('    Linked to this team: '.($resub['linked_to_team'] ? 'Yes' : 'No'));
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
