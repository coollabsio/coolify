<?php

namespace App\Actions\User;

use App\Actions\Stripe\CancelSubscription;
use App\Models\GithubApp;
use App\Models\GithubRunnerConfig;
use App\Models\GithubRunnerExecution;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use App\Services\AvatarStorageService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Lets a user delete their own account.
 *
 * Deletion is refused while the user still owns data that would be lost or
 * orphaned. The user must resolve each blocker first, the same way team
 * deletion requires an empty team. With $removeTeamResources, the servers,
 * resources, projects, and Git sources of teams where the user is the only
 * member are removed from Coolify instead. Nothing is stopped on the servers.
 */
class DeleteUserAccount
{
    /**
     * @return list<string>
     */
    public function blockers(User $user, bool $removeTeamResources = false): array
    {
        if ($user->id === 0) {
            return ['The root user cannot be deleted.'];
        }

        $blockers = [];

        foreach ($user->teams()->with('members')->get() as $team) {
            $role = $team->pivot->role;
            $isAlone = $team->members->count() === 1;

            if ($team->id === 0) {
                if ($isAlone) {
                    $blockers[] = 'You are the only member of the root team.';
                }

                continue;
            }

            if ($role !== 'owner') {
                continue;
            }

            if ($isAlone) {
                if (! $removeTeamResources && ! $team->isEmpty()) {
                    $blockers[] = "Delete all projects, servers, and Git sources of the team \"{$team->name}\".";
                }

                continue;
            }

            $hasReplacementOwner = $team->members->contains(
                fn (User $member): bool => $member->id !== $user->id && in_array($member->pivot->role, ['owner', 'admin'], true)
            );

            if (! $hasReplacementOwner) {
                $blockers[] = "Make another member of the team \"{$team->name}\" an admin or owner.";
            }
        }

        return $blockers;
    }

    public function handle(User $user, bool $removeTeamResources = false): void
    {
        if ($blockers = $this->blockers($user, $removeTeamResources)) {
            throw new RuntimeException(implode(' ', $blockers));
        }

        foreach ($this->subscriptionTeams($user) as $team) {
            if (! CancelSubscription::cancelById($team->subscription->stripe_subscription_id)) {
                throw new RuntimeException("Could not cancel the subscription of the team \"{$team->name}\". Your account was not deleted. Please try again.");
            }
        }

        try {
            app(AvatarStorageService::class)->delete($user);
        } catch (\Throwable $e) {
            report($e);
        }

        DB::transaction(function () use ($user, $removeTeamResources): void {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);

            if ($blockers = $this->blockers($user, $removeTeamResources)) {
                throw new RuntimeException(implode(' ', $blockers));
            }

            if ($removeTeamResources) {
                $this->singleMemberTeams($user)->each(fn (Team $team) => $this->removeTeamResources($team));
            }

            // The subscription row of a deleted team would point to a missing
            // team, and later Stripe webhooks for its customer would fail.
            Subscription::query()->whereIn('team_id', $this->singleMemberTeams($user)->pluck('id'))->delete();

            // Deletes the user's single-member teams, promotes an admin where
            // the user was the only owner, and removes the user from the rest.
            (new DeleteUserTeams($user))->execute();

            // team_user has no foreign keys, so also remove the root team
            // membership and the rows of teams deleted above.
            $user->teams()->detach();

            $user->fresh()->delete();

            DB::table('sessions')->where('user_id', $user->id)->delete();
        });
    }

    /**
     * @return list<string>
     */
    public function confirmationActions(User $user): array
    {
        return $this->subscriptionTeams($user)
            ->map(fn (Team $team): string => "The subscription of the team \"{$team->name}\" will be cancelled immediately. This is required.")
            ->push('Your account will be permanently deleted from Coolify.')
            ->values()
            ->all();
    }

    private function singleMemberTeams(User $user): Collection
    {
        return $user->teams()
            ->where('teams.id', '!=', 0)
            ->wherePivot('role', 'owner')
            ->withCount('members')
            ->get()
            ->filter(fn (Team $team): bool => $team->members_count === 1);
    }

    /**
     * Removes the team's data from Coolify. Git sources are removed when the
     * team itself is deleted.
     */
    private function removeTeamResources(Team $team): void
    {
        $this->forgetGithubRunners($team);

        foreach ($team->servers()->get() as $server) {
            foreach ($server->definedResources() as $resource) {
                $resource->forceDelete();
            }
            $server->forceDelete();
        }

        foreach ($team->projects()->get() as $project) {
            $project->delete();
        }
    }

    /**
     * Removes the team's GitHub Actions runner records so that busy runners do
     * not block the removal of its servers and Git sources. The runners keep
     * running on the servers; Coolify only stops tracking them.
     */
    private function forgetGithubRunners(Team $team): void
    {
        $githubAppIds = GithubApp::query()->where('team_id', $team->id)->where('is_system_wide', false)->pluck('id');
        $serverIds = $team->servers()->pluck('id');

        $belongsToTeam = fn ($query) => $query->whereIn('github_app_id', $githubAppIds)->orWhereIn('server_id', $serverIds);

        GithubRunnerExecution::query()->where($belongsToTeam)->delete();
        GithubRunnerConfig::query()->where($belongsToTeam)->delete();
    }

    private function subscriptionTeams(User $user): Collection
    {
        if (! isCloud()) {
            return collect();
        }

        return $user->teams()
            ->where('teams.id', '!=', 0)
            ->wherePivot('role', 'owner')
            ->with('subscription')
            ->get()
            ->filter(fn (Team $team): bool => filled($team->subscription?->stripe_subscription_id));
    }
}
