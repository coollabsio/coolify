<?php

namespace App\Console\Commands\Cloud;

use App\Models\Team;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CleanupUnsubscribedTeams extends Command
{
    protected $signature = 'cloud:cleanup-unsubscribed-teams
                            {--months=6 : Months since the subscription ended}
                            {--yes : Delete eligible teams instead of running a dry run}';

    protected $description = 'Delete teams whose subscription ended long ago, with their servers, resources, projects, and users without another team';

    public function handle(): int
    {
        if (! isCloud()) {
            $this->error('This command can only be run on Coolify Cloud.');

            return self::FAILURE;
        }

        $months = filter_var($this->option('months'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($months === false) {
            $this->error('The --months option must be a positive integer.');

            return self::FAILURE;
        }

        $cutoff = now()->subMonths($months);
        $teams = $this->eligibleTeams($cutoff)
            ->with('subscription')
            ->withCount(['servers', 'projects', 'members'])
            ->get();
        $orphanedUserIds = $this->orphanedUsers($teams->modelKeys())->pluck('id');

        $this->info("Found {$teams->count()} ".Str::plural('team', $teams->count())." with a subscription that ended before {$cutoff->toDateString()}.");
        $this->info("Found {$orphanedUserIds->count()} ".Str::plural('user', $orphanedUserIds->count()).' without another team.');

        if ($teams->isNotEmpty()) {
            $this->table(
                ['Team', 'Name', 'Servers', 'Projects', 'Members', 'Subscription updated'],
                $teams->map(fn (Team $team) => [
                    $team->id,
                    $team->name,
                    $team->servers_count,
                    $team->projects_count,
                    $team->members_count,
                    $team->subscription->updated_at?->toDateString(),
                ]),
            );
        }

        if (! $this->option('yes')) {
            $this->warn('Dry run only. Use --yes to delete eligible teams.');

            return self::SUCCESS;
        }

        foreach ($teams as $team) {
            DB::transaction(fn () => $this->deleteTeam($team));
        }

        $deletedUserCount = 0;

        foreach (User::query()->whereKey($orphanedUserIds)->whereDoesntHave('teams')->lazyById(100) as $user) {
            if ($user->delete()) {
                $deletedUserCount++;
            }
        }

        $this->info("Deleted {$teams->count()} ".Str::plural('team', $teams->count())." and {$deletedUserCount} ".Str::plural('user', $deletedUserCount).'.');

        return self::SUCCESS;
    }

    /**
     * Teams whose Stripe subscription ended (no subscription id, unpaid) and did not change since the cutoff.
     */
    private function eligibleTeams(CarbonInterface $cutoff): Builder
    {
        return Team::query()
            ->whereKeyNot(0)
            ->whereHas('subscription', fn (Builder $query) => $query
                ->where('stripe_invoice_paid', false)
                ->whereNull('stripe_subscription_id')
                ->whereNotNull('stripe_customer_id')
                ->where('updated_at', '<', $cutoff));
    }

    /**
     * Users that belong only to the given teams.
     *
     * @param  array<int, int>  $teamIds
     */
    private function orphanedUsers(array $teamIds): Builder
    {
        return User::query()
            ->whereKeyNot(0)
            ->whereHas('teams', fn (Builder $query) => $query->whereIn('teams.id', $teamIds))
            ->whereDoesntHave('teams', fn (Builder $query) => $query->whereNotIn('teams.id', $teamIds));
    }

    private function deleteTeam(Team $team): void
    {
        foreach ($team->servers()->withTrashed()->get() as $server) {
            foreach ($server->definedResources() as $resource) {
                $resource->forceDelete();
            }
            $server->forceDelete();
        }

        foreach ($team->projects()->get() as $project) {
            $project->delete();
        }

        foreach ($team->members()->get() as $member) {
            $member->clearStoredTeamIfMatches($team->id);
            DB::table('sessions')->where('user_id', $member->id)->delete();
            Cache::forget("user:{$member->id}:team:{$team->id}");
        }
        $team->members()->detach();

        $team->subscription()->delete();
        $team->delete();
    }
}
