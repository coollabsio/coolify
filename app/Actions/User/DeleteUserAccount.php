<?php

namespace App\Actions\User;

use App\Actions\Stripe\CancelSubscription;
use App\Actions\Team\DeleteTeamData;
use App\Models\Team;
use App\Models\User;
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

        DB::transaction(function () use ($user, $removeTeamResources): void {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);

            if ($blockers = $this->blockers($user, $removeTeamResources)) {
                throw new RuntimeException(implode(' ', $blockers));
            }

            // Without $removeTeamResources the blockers above guarantee these teams hold no
            // projects, servers, or sources.
            $this->singleMemberTeams($user)->each(fn (Team $team) => app(DeleteTeamData::class)->handle($team));

            // Promotes an admin where the user was the only owner, and removes
            // the user from the remaining teams.
            (new DeleteUserTeams($user))->execute();

            // team_user has no foreign keys, so also remove the root team membership.
            $user->teams()->detach();

            app(DeleteTeamlessUser::class)->handle($user->fresh());
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
