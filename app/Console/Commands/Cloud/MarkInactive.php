<?php

namespace App\Console\Commands\Cloud;

use App\Models\Team;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MarkInactive extends Command
{
    protected $signature = 'cloud:mark-inactive
        {--dry-run : Only report what would happen, do not modify anything}';

    protected $description = 'Mark inactive teams and users (no active subscription for 90+ days). Run once before cloud:send-maintenance-email.';

    private const int INACTIVE_THRESHOLD_DAYS = 90;

    private const int NOTICE_DAYS = 30; // The maintenance email will be sent 30 days before the maintenance window.

    public function handle(): int
    {
        if (! isDev() && ! isCloud()) {
            $this->error('This command can only be run on Coolify Cloud.');

            return 1;
        }

        $thresholdDays = self::INACTIVE_THRESHOLD_DAYS - self::NOTICE_DAYS;
        $cutoff = now()->subDays($thresholdDays);
        $dryRun = (bool) $this->option('dry-run');

        $this->info(sprintf(
            'Marking inactive: anyone inactive since before %s (%d days; %d-day threshold minus %d-day notice).',
            $cutoff->toDateTimeString(),
            $thresholdDays,
            self::INACTIVE_THRESHOLD_DAYS,
            self::NOTICE_DAYS,
        ));
        if ($dryRun) {
            $this->warn('Dry run: no changes will be persisted.');
        }
        $this->newLine();

        $stats = [
            'teams_checked' => 0,
            'teams_marked_inactive' => 0,
            'users_checked' => 0,
            'users_marked_inactive' => 0,
        ];

        DB::transaction(function () use ($cutoff, $dryRun, &$stats) {
            $this->processTeams($cutoff, $dryRun, $stats);
            $this->processUsers($dryRun, $stats);
        });

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            collect($stats)->map(fn ($value, $key) => [$key, $value])->values()->all(),
        );

        return 0;
    }

    private function processTeams(Carbon $cutoff, bool $dryRun, array &$stats): void
    {
        Team::query()
            ->where('id', '!=', 0)
            ->with('subscription:id,team_id,stripe_invoice_paid,ended_at')
            ->orderBy('id')
            ->chunkById(500, function ($teams) use ($cutoff, $dryRun, &$stats) {
                foreach ($teams as $team) {
                    $stats['teams_checked']++;

                    $shouldBeInactive = $this->teamShouldBeInactive($team, $cutoff);

                    if ($shouldBeInactive && ! $team->is_inactive) {
                        $stats['teams_marked_inactive']++;
                    }

                    if (! $dryRun) {
                        $team->is_inactive = $shouldBeInactive;
                        $team->save();
                    }
                }
            });
    }

    private function teamShouldBeInactive(Team $team, Carbon $cutoff): bool
    {
        if ($team->subscription?->stripe_invoice_paid) {
            return false;
        }

        $inactiveSince = $team->subscription?->ended_at ?? $team->created_at;
        if ($inactiveSince === null) {
            return false;
        }

        return $inactiveSince <= $cutoff;
    }

    private function processUsers(bool $dryRun, array &$stats): void
    {
        // A user is marked inactive when ALL of their teams are inactive.
        User::query()
            ->orderBy('id')
            ->chunkById(500, function ($users) use ($dryRun, &$stats) {
                foreach ($users as $user) {
                    $stats['users_checked']++;

                    $teams = $user->teams()
                        ->where('teams.id', '!=', 0)
                        ->get(['teams.id', 'teams.is_inactive']);

                    $shouldBeInactive = $this->userShouldBeInactive($teams);

                    if ($shouldBeInactive && ! $user->is_inactive) {
                        $stats['users_marked_inactive']++;
                    }

                    if (! $dryRun) {
                        $user->is_inactive = $shouldBeInactive;
                        $user->save();
                    }
                }
            });
    }

    /**
     * @param  Collection<int, Team>  $teams
     */
    private function userShouldBeInactive($teams): bool
    {
        if ($teams->isEmpty()) {
            return false;
        }

        foreach ($teams as $team) {
            if (! $team->is_inactive) {
                return false;
            }
        }

        return true;
    }
}
