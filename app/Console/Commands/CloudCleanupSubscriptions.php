<?php

namespace App\Console\Commands;

use App\Events\ServerReachabilityChanged;
use App\Models\Team;
use Illuminate\Console\Command;

class CloudCleanupSubscriptions extends Command
{
    protected $signature = 'cloud:cleanup-subs';

    protected $description = 'Cleanup subcriptions teams';

    public function handle()
    {
        try {
            if (! isCloud()) {
                $this->error('This command can only be run on cloud');

                return;
            }
            $this->info('Cleaning up subcriptions teams');
            $stripe = new \Stripe\StripeClient(config('subscription.stripe_api_key'));

            $teams = Team::all()->filter(function ($team) {
                return $team->id !== 0;
            })->sortBy('id');
            foreach ($teams as $team) {
                if ($team) {
                    $this->info("Checking team {$team->id}");
                }
                if (! data_get($team, 'subscription')) {
                    $this->disableServers($team);

                    continue;
                }
                // If the team has no subscription id and the invoice is paid, we need to reset the invoice paid status
                if (! (data_get($team, 'subscription.stripe_subscription_id'))) {
                    $this->info("Resetting invoice paid status for team {$team->id}");

                    $team->subscription->update([
                        'stripe_invoice_paid' => false,
                        'stripe_trial_already_ended' => false,
                        'stripe_subscription_id' => null,
                    ]);
                    $this->disableServers($team);

                    continue;
                } else {
                    $subscription = $stripe->subscriptions->retrieve(data_get($team, 'subscription.stripe_subscription_id'), []);
                    $status = data_get($subscription, 'status');
                    if ($status === 'active' || $status === 'past_due') {
                        $team->subscription->update([
                            'stripe_invoice_paid' => true,
                            'stripe_trial_already_ended' => false,
                        ]);

                        continue;
                    }
                    $this->info('Subscription status: '.$status);
                    $this->info('Subscription id: '.data_get($team, 'subscription.stripe_subscription_id'));
                    $confirm = $this->confirm('Do you want to cancel the subscription?', true);
                    if (! $confirm) {
                        $this->info("Skipping team {$team->id}");
                    } else {
                        $this->info("Cancelling subscription for team {$team->id}");
                        $team->subscription->update([
                            'stripe_invoice_paid' => false,
                            'stripe_trial_already_ended' => false,
                            'stripe_subscription_id' => null,
                        ]);
                        $this->disableServers($team);
                    }
                }
            }
        } catch (\Exception $e) {
            $this->error($e->getMessage());

            return;
        }
    }

    private function disableServers(Team $team)
    {
        foreach ($team->servers as $server) {
            if ($server->settings->is_usable === true || $server->settings->is_reachable === true || $server->ip !== '1.2.3.4') {
                $this->info("Disabling server {$server->id} {$server->name}");
                $server->settings()->update([
                    'is_usable' => false,
                    'is_reachable' => false,
                ]);
                $server->update([
                    'ip' => '1.2.3.4',
                ]);

                ServerReachabilityChanged::dispatch($server);
            }
        }
    }
}
