<?php

namespace App\Console\Commands\Cloud;

use App\Actions\Stripe\CancelSubscription;
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
use RuntimeException;
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

        $stripeChecks = $teams->mapWithKeys(fn (Team $team): array => [$team->id => $this->stripeCheck($team)]);

        $this->info("Found {$teams->count()} ".Str::plural('team', $teams->count())." that stopped paying or never paid before {$cutoff->toDateString()}.");

        if ($teams->isNotEmpty()) {
            $this->table(
                ['Team', 'Name', 'Action', 'Servers', 'Projects', 'Members', 'Subscription ended', 'Created'],
                $teams->map(fn (Team $team) => [
                    $team->id,
                    $team->name,
                    $this->action($team, $stripeChecks[$team->id]),
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
        /** @var array<int, string> $skippedTeams team id => reason */
        $skippedTeams = [];
        /** @var array<int, string> $failedTeams team id => error */
        $failedTeams = [];

        foreach ($teams as $team) {
            ['hold' => $hold, 'cancel' => $subscriptionIds] = $stripeChecks[$team->id];
            if ($hold) {
                $skippedTeams[$team->id] = $hold;
                Log::warning('Skipped an unsubscribed team.', ['team_id' => $team->id, 'reason' => $hold]);

                continue;
            }

            try {
                // Cancel first, so a failed cancel keeps the team for the next run.
                foreach ($subscriptionIds as $subscriptionId) {
                    if (! CancelSubscription::cancelById($subscriptionId)) {
                        throw new RuntimeException("Could not cancel the Stripe subscription {$subscriptionId}.");
                    }
                }

                $result = $this->deleteTeam($team, $cutoff);
            } catch (Throwable $e) {
                $failedTeams[$team->id] = $e->getMessage();
                Log::error('Could not delete an unsubscribed team.', ['team_id' => $team->id, 'error' => $e->getMessage()]);

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
                'cancelled_stripe_subscriptions' => $subscriptionIds,
                ...$result,
            ]);
        }

        $this->info("Deleted {$deletedTeamCount} ".Str::plural('team', $deletedTeamCount)." and {$deletedUserCount} ".Str::plural('user', $deletedUserCount).'.');
        if ($keptTeamCount > 0) {
            $this->info("Removed the data of {$keptTeamCount} personal ".Str::plural('team', $keptTeamCount).' and kept '.($keptTeamCount === 1 ? 'the team' : 'the teams').'.');
        }

        if ($skippedTeams) {
            $this->warn('Skipped '.count($skippedTeams).' '.Str::plural('team', count($skippedTeams)).' because of Stripe. See the log for details.');
        }

        if ($failedTeams) {
            $this->error('Could not delete '.count($failedTeams).' '.Str::plural('team', count($failedTeams)).'. See the log for details.');
        }

        if ($skippedTeams || $failedTeams) {
            send_internal_notification($this->summary($skippedTeams, $failedTeams));
        }

        return $failedTeams ? self::FAILURE : self::SUCCESS;

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
     * Checks the Stripe subscriptions of the team's customers and of every customer with the email of
     * an owner or admin. A subscription counts for the team unless it belongs to another team that
     * exists. A paying one, or a failed check, keeps the team ('hold'). The others that did not end,
     * for example unpaid or paused ones, are cancelled before the team is deleted ('cancel').
     *
     * @return array{hold: ?string, cancel: list<string>}
     */
    private function stripeCheck(Team $team): array
    {
        try {
            $subscriptions = app(FindOpenStripeSubscriptions::class)
                ->handle(
                    $team->members()->wherePivotIn('role', ['owner', 'admin'])->pluck('email'),
                    $team->subscriptions()->pluck('stripe_customer_id'),
                )
                ->filter(function (StripeSubscription $subscription) use ($team): bool {
                    $teamId = FindOpenStripeSubscriptions::teamId($subscription);

                    return $teamId === null || $teamId === $team->id || Team::query()->whereKey($teamId)->doesntExist();
                });
        } catch (Throwable $e) {
            return ['hold' => "keep: Stripe check failed ({$e->getMessage()})", 'cancel' => []];
        }

        $paying = $subscriptions->first(fn (StripeSubscription $subscription): bool => in_array($subscription->status, FindOpenStripeSubscriptions::PAYING_STATUSES, true));

        return [
            'hold' => $paying ? "keep: Stripe subscription {$paying->id} is {$paying->status}" : null,
            'cancel' => $paying ? [] : $subscriptions->keys()->all(),
        ];
    }

    /**
     * @param  array{hold: ?string, cancel: list<string>}  $stripeCheck
     */
    private function action(Team $team, array $stripeCheck): string
    {
        if ($stripeCheck['hold']) {
            return $stripeCheck['hold'];
        }

        $action = $this->keepsTeam($team) ? 'remove data, keep team' : 'delete team';

        return $stripeCheck['cancel'] ? "{$action}, cancel ".implode(', ', $stripeCheck['cancel']) : $action;
    }

    /**
     * One notification per run, so a large run or a Stripe outage does not send one per team.
     *
     * @param  array<int, string>  $skippedTeams
     * @param  array<int, string>  $failedTeams
     */
    private function summary(array $skippedTeams, array $failedTeams): string
    {
        $lines = ['cloud:cleanup-unsubscribed-teams:'];
        foreach (['Skipped' => $skippedTeams, 'Could not delete' => $failedTeams] as $label => $teams) {
            if ($teams) {
                $lines[] = "{$label} ".count($teams).' '.Str::plural('team', count($teams)).':';
                foreach (array_slice($teams, 0, 20, true) as $teamId => $reason) {
                    $lines[] = "- team {$teamId}: {$reason}";
                }
                if (count($teams) > 20) {
                    $lines[] = '- and '.(count($teams) - 20).' more. See the log.';
                }
            }
        }

        return implode("\n", $lines);
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
