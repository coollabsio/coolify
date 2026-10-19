<?php

namespace App\Console\Commands\Cloud;

use App\Actions\Team\DeleteTeamData;
use App\Actions\User\DeleteTeamlessUser;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class CleanupUnsubscribedTeams extends Command
{
    protected $signature = 'cloud:cleanup-unsubscribed-teams
                            {--days= : Days since the subscription ended or the never-paid team was created (default: constants.cloud.data_retention_days)}
                            {--yes : Delete eligible teams instead of running a dry run}';

    protected $description = 'Delete teams that stopped paying or never paid, with their servers, resources, projects, and users without another team';

    public function handle(): int
    {
        if (! isCloud()) {
            $this->error('This command can only be run on Coolify Cloud.');

            return self::FAILURE;
        }

        $days = filter_var($this->option('days') ?? config('constants.cloud.data_retention_days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($days === false) {
            $this->error('The --days option must be a positive integer.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $teams = $this->eligibleTeams($cutoff)
            ->withMax('subscriptions', 'ended_at')
            ->withCount(['servers', 'projects', 'members'])
            ->orderBy('id')
            ->get();

        $this->info("Found {$teams->count()} ".Str::plural('team', $teams->count())." that stopped paying or never paid before {$cutoff->toDateString()}.");

        if ($teams->isNotEmpty()) {
            $this->table(
                ['Team', 'Name', 'Servers', 'Projects', 'Members', 'Subscription ended', 'Created'],
                $teams->map(fn (Team $team) => [
                    $team->id,
                    $team->name,
                    $team->servers_count,
                    $team->projects_count,
                    $team->members_count,
                    $team->subscriptions_max_ended_at ? Str::before($team->subscriptions_max_ended_at, ' ') : 'never paid',
                    $team->created_at?->toDateString(),
                ]),
            );
        }

        if (! $this->option('yes')) {
            $this->warn('Dry run only. Use --yes to delete eligible teams.');

            return self::SUCCESS;
        }

        $deletedTeamCount = 0;
        $deletedUserCount = 0;
        $failedTeamCount = 0;

        foreach ($teams as $team) {
            try {
                $result = $this->deleteTeam($team, $cutoff);
            } catch (Throwable $e) {
                $failedTeamCount++;
                Log::error('Could not delete an unsubscribed team.', ['team_id' => $team->id, 'error' => $e->getMessage()]);
                send_internal_notification("cloud:cleanup-unsubscribed-teams could not delete team {$team->id}: {$e->getMessage()}");

                continue;
            }

            if ($result === null) {
                continue;
            }

            $deletedTeamCount++;
            $deletedUserCount += $result['users'];
            Log::info('Deleted an unsubscribed team.', [
                'team_id' => $team->id,
                'subscription_ended_at' => $team->subscriptions_max_ended_at,
                'team_created_at' => $team->created_at?->toDateTimeString(),
                ...$result,
            ]);
        }

        $this->info("Deleted {$deletedTeamCount} ".Str::plural('team', $deletedTeamCount)." and {$deletedUserCount} ".Str::plural('user', $deletedUserCount).'.');

        if ($failedTeamCount > 0) {
            $this->error("Could not delete {$failedTeamCount} ".Str::plural('team', $failedTeamCount).'. See the log for details.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Deletes the team and its members that have no other team, as one unit, so a failure leaves
     * both in place for the next run. Returns null when the team is no longer eligible.
     *
     * @return array{servers: int, resources: int, projects: int, users: int}|null
     */
    private function deleteTeam(Team $team, CarbonInterface $cutoff): ?array
    {
        return DB::transaction(function () use ($team, $cutoff): ?array {
            $team = $this->eligibleTeams($cutoff)->whereKey($team->id)->lockForUpdate()->first();
            if (! $team) {
                return null;
            }

            $memberIds = $team->members()->pluck('users.id');
            $counts = app(DeleteTeamData::class)->handle($team);

            $teamlessMembers = User::query()->whereKey($memberIds)->whereKeyNot(0)->whereDoesntHave('teams')->get();
            foreach ($teamlessMembers as $user) {
                app(DeleteTeamlessUser::class)->handle($user);
            }

            return [...$counts, 'users' => $teamlessMembers->count()];
        });
    }

    /**
     * Teams without a paying (paid or past due) subscription row, where either every row ended
     * before the cutoff, or the team never had a Stripe customer, was created before the cutoff,
     * and none of its members belongs to another team.
     */
    private function eligibleTeams(CarbonInterface $cutoff): Builder
    {
        return Team::query()
            ->whereKeyNot(0)
            ->whereDoesntHave('subscriptions', fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->where('stripe_invoice_paid', true)->orWhere('stripe_past_due', true)))
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query
                    ->whereHas('subscriptions', fn (Builder $query) => $query->whereNotNull('stripe_customer_id'))
                    ->whereDoesntHave('subscriptions', fn (Builder $query) => $query
                        ->where(fn (Builder $query) => $query->whereNull('ended_at')->orWhere('ended_at', '>=', $cutoff))))
                ->orWhere(fn (Builder $query) => $query
                    ->whereDoesntHave('subscriptions', fn (Builder $query) => $query->whereNotNull('stripe_customer_id'))
                    ->where('created_at', '<', $cutoff)
                    ->whereNotExists(fn (QueryBuilder $query) => $query
                        ->selectRaw('1')
                        ->from('team_user as membership')
                        ->join('team_user as other_membership', 'other_membership.user_id', '=', 'membership.user_id')
                        ->whereColumn('membership.team_id', 'teams.id')
                        ->whereColumn('other_membership.team_id', '!=', 'teams.id'))));
    }
}
