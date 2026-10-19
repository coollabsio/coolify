<?php

namespace App\Console\Commands\Cloud;

use App\Actions\Stripe\FindOpenStripeSubscriptions;
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
use Stripe\Subscription as StripeSubscription;
use Throwable;

class CleanupUnsubscribedTeams extends Command
{
    protected $signature = 'cloud:cleanup-unsubscribed-teams
                            {--days= : Days since the subscription ended or the never-paid team was created (default: constants.cloud.data_retention_days)}
                            {--yes : Delete eligible teams instead of running a dry run}';

    protected $description = 'Delete teams that stopped paying or never paid, with their servers, resources, projects, and users without another team. A personal team of a member of another team keeps only the team and its members';

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

        // A subscription at Stripe that Coolify has no paying row for, for example after a second
        // checkout replaced the subscription id of the row, keeps the team.
        $stripeHolds = $teams->mapWithKeys(fn (Team $team): array => [$team->id => $this->stripeHold($team)]);

        $this->info("Found {$teams->count()} ".Str::plural('team', $teams->count())." that stopped paying or never paid before {$cutoff->toDateString()}.");

        if ($teams->isNotEmpty()) {
            $this->table(
                ['Team', 'Name', 'Action', 'Servers', 'Projects', 'Members', 'Subscription ended', 'Created'],
                $teams->map(fn (Team $team) => [
                    $team->id,
                    $team->name,
                    $stripeHolds[$team->id] ?? ($this->keepsTeam($team) ? 'remove data, keep team' : 'delete team'),
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
        $keptTeamCount = 0;
        $deletedUserCount = 0;
        $failedTeamCount = 0;
        $skippedTeamCount = 0;

        foreach ($teams as $team) {
            if ($hold = $stripeHolds[$team->id]) {
                $skippedTeamCount++;
                Log::warning('Skipped an unsubscribed team.', ['team_id' => $team->id, 'reason' => $hold]);
                send_internal_notification("cloud:cleanup-unsubscribed-teams skipped team {$team->id}: {$hold}. Check the subscription in Stripe.");

                continue;
            }

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

            if ($result['kept_team']) {
                $keptTeamCount++;
            } else {
                $deletedTeamCount++;
            }
            $deletedUserCount += $result['users'];
            Log::info($result['kept_team'] ? 'Removed the data of an unsubscribed personal team and kept the team.' : 'Deleted an unsubscribed team.', [
                'team_id' => $team->id,
                'subscription_ended_at' => $team->subscriptions_max_ended_at,
                'team_created_at' => $team->created_at?->toDateTimeString(),
                ...$result,
            ]);
        }

        $this->info("Deleted {$deletedTeamCount} ".Str::plural('team', $deletedTeamCount)." and {$deletedUserCount} ".Str::plural('user', $deletedUserCount).'.');
        if ($keptTeamCount > 0) {
            $this->info("Removed the data of {$keptTeamCount} personal ".Str::plural('team', $keptTeamCount).' and kept '.($keptTeamCount === 1 ? 'the team' : 'the teams').'.');
        }

        if ($skippedTeamCount > 0) {
            $this->warn("Skipped {$skippedTeamCount} ".Str::plural('team', $skippedTeamCount).' because of Stripe. See the log for details.');
        }

        if ($failedTeamCount > 0) {
            $this->error("Could not delete {$failedTeamCount} ".Str::plural('team', $failedTeamCount).'. See the log for details.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Deletes the team and its members that have no other team, as one unit, so a failure leaves
     * both in place for the next run. A team that keepsTeam() loses only its data. Returns null
     * when the team is no longer eligible.
     *
     * @return array{servers: int, resources: int, projects: int, users: int, kept_team: bool}|null
     */
    private function deleteTeam(Team $team, CarbonInterface $cutoff): ?array
    {
        return DB::transaction(function () use ($team, $cutoff): ?array {
            $team = $this->eligibleTeams($cutoff)->whereKey($team->id)->lockForUpdate()->first();
            if (! $team) {
                return null;
            }

            if ($this->keepsTeam($team)) {
                return [...app(DeleteTeamData::class)->purge($team), 'users' => 0, 'kept_team' => true];
            }

            $memberIds = $team->members()->pluck('users.id');
            $counts = app(DeleteTeamData::class)->handle($team);

            $teamlessMembers = User::query()->whereKey($memberIds)->whereKeyNot(0)->whereDoesntHave('teams')->get();
            foreach ($teamlessMembers as $user) {
                app(DeleteTeamlessUser::class)->handle($user);
            }

            return [...$counts, 'users' => $teamlessMembers->count(), 'kept_team' => false];
        });
    }

    /**
     * Why Stripe keeps the team, or null. Checks the team's Stripe customers and every customer with
     * the email of an owner or admin. A paying subscription keeps the team unless it belongs to
     * another team that exists. A failed check also keeps the team.
     */
    private function stripeHold(Team $team): ?string
    {
        try {
            $subscription = app(FindOpenStripeSubscriptions::class)
                ->handle(
                    $team->members()->wherePivotIn('role', ['owner', 'admin'])->pluck('email'),
                    $team->subscriptions()->pluck('stripe_customer_id'),
                )
                ->filter(fn (StripeSubscription $subscription): bool => in_array($subscription->status, FindOpenStripeSubscriptions::PAYING_STATUSES, true))
                ->first(function (StripeSubscription $subscription) use ($team): bool {
                    $teamId = FindOpenStripeSubscriptions::teamId($subscription);

                    return $teamId === null || $teamId === $team->id || Team::query()->whereKey($teamId)->doesntExist();
                });
        } catch (Throwable $e) {
            return "keep: Stripe check failed ({$e->getMessage()})";
        }

        return $subscription ? "keep: Stripe subscription {$subscription->id} is {$subscription->status}" : null;
    }

    /**
     * Whether the team is the personal team of a user who also belongs to another team. Such a team
     * keeps its row and members, so the user keeps their personal team.
     */
    private function keepsTeam(Team $team): bool
    {
        return $team->personal_team && DB::table('team_user')
            ->whereIn('user_id', $team->members()->select('users.id'))
            ->where('team_id', '!=', $team->id)
            ->exists();
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
