<?php

namespace App\Console\Commands;

use App\Jobs\SyncMailcheepContactJob;
use App\Models\Team;
use Illuminate\Console\Command;

class SyncMailcheep extends Command
{
    protected $signature = 'sync:mailcheep {--dry-run}';

    protected $description = 'Backfill all team owners to Mailcheep contact lists';

    public function handle(): int
    {
        if (! isCloud()) {
            $this->error('This command can only be run on the cloud instance.');

            return self::FAILURE;
        }

        if (! config('subscription.mailcheep_api_key')) {
            $this->error('MAILCHEEP_API_KEY is not configured.');

            return self::FAILURE;
        }

        $dryRun = $this->option('dry-run');
        $stats = ['subscribed' => 0, 'churned' => 0, 'no_subscription' => 0];

        Team::with(['subscription', 'members'])->chunk(100, function ($teams) use ($dryRun, &$stats) {
            foreach ($teams as $team) {
                $owner = $team->members->firstWhere('pivot.role', 'owner');

                if (! $owner) {
                    continue;
                }

                $subscription = $team->subscription;

                if ($subscription && $subscription->stripe_invoice_paid && $subscription->stripe_subscription_id) {
                    $action = 'create_or_update';
                    $extraFields = [
                        'team_id' => (string) $team->id,
                        'plan' => $subscription->billingInterval(),
                    ];
                    $stats['subscribed']++;
                } elseif ($subscription && (! $subscription->stripe_invoice_paid || ! $subscription->stripe_subscription_id)) {
                    $action = 'add_to_churned';
                    $extraFields = [];
                    $stats['churned']++;
                } else {
                    $action = 'create_or_update';
                    $extraFields = ['team_id' => (string) $team->id];
                    $stats['no_subscription']++;
                }

                if ($dryRun) {
                    $this->line("[DRY RUN] {$action}: {$owner->email} (Team #{$team->id})");
                } else {
                    SyncMailcheepContactJob::dispatch(
                        action: $action,
                        email: $owner->email,
                        name: $owner->name,
                        customFields: $extraFields,
                    );
                }
            }
        });

        $this->info("Subscribed: {$stats['subscribed']}, Churned: {$stats['churned']}, No subscription: {$stats['no_subscription']}");

        return self::SUCCESS;
    }
}
