<?php

namespace App\Actions\User;

use App\Actions\Stripe\CancelSubscription;
use App\Actions\Stripe\FindOpenStripeSubscriptions;
use App\Actions\Team\DeleteTeamData;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Lets a user delete their own account.
 *
 * Deletion is refused while the user still owns data that would be lost or
 * orphaned. The user must resolve each blocker first, the same way team
 * deletion requires an empty team. When removesDataOf() allows it, the servers,
 * resources, projects, and Git sources of a team where the user is the only
 * member are removed from Coolify instead. Nothing is stopped on the servers.
 *
 * $cancelledTeam is the team whose subscription the calling action cancels
 * together with the account deletion. Its subscriptions count as not paying.
 */
class DeleteUserAccount
{
    /**
     * @return list<string>
     */
    public function blockers(User $user, ?Team $cancelledTeam = null): array
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
                if (! $this->removesDataOf($team, $cancelledTeam) && ! $team->isEmpty()) {
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

    public function handle(User $user, ?Team $cancelledTeam = null): void
    {
        if ($blockers = $this->blockers($user, $cancelledTeam)) {
            throw new RuntimeException(implode(' ', $blockers));
        }

        foreach ($this->subscriptionsToCancel($user) as $subscriptionId => $team) {
            if (! CancelSubscription::cancelById($subscriptionId)) {
                $owner = $team ? "the team \"{$team->name}\"" : 'your email';

                throw new RuntimeException("Could not cancel the subscription of {$owner}. Your account was not deleted. Please try again.");
            }
        }

        DB::transaction(function () use ($user, $cancelledTeam): void {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);

            if ($blockers = $this->blockers($user, $cancelledTeam)) {
                throw new RuntimeException(implode(' ', $blockers));
            }

            // Unless removesDataOf() holds for a team, the blockers above guarantee it holds no
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
    public function confirmationActions(User $user, ?Team $cancelledTeam = null): array
    {
        $removedTeams = $this->singleMemberTeams($user)
            ->filter(fn (Team $team): bool => $this->removesDataOf($team, $cancelledTeam) && ! $team->isEmpty());

        return $this->subscriptionConfirmationActions($user, $cancelledTeam)
            ->concat($removedTeams->map(fn (Team $team): string => "The servers, projects, and Git sources of the team \"{$team->name}\" will be removed from Coolify. Nothing is stopped on the servers."))
            ->push('Your account will be permanently deleted from Coolify.')
            ->values()
            ->all();
    }

    /**
     * Whether self-service deletion removes the data of a single-member team instead of asking the
     * user to delete it first. On Coolify Cloud, a team without a paid or past-due subscription
     * cannot open its project and server pages, so the user could not do that. The data of a
     * paying team stays until the user deletes it. $cancelledTeam does not count as paying.
     */
    private function removesDataOf(Team $team, ?Team $cancelledTeam): bool
    {
        return isCloud() && ($team->is($cancelledTeam)
            || ! $team->subscriptions()->get()->contains(fn (Subscription $subscription): bool => $subscription->isPaying()));
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
     * The Stripe subscriptions that account deletion cancels, with the team each belongs to. These
     * are the subscription rows of the teams the user owns, and every open Stripe subscription of the
     * user's email and of those teams' customers, also when Coolify has no row for it. Subscriptions
     * of a team that the user does not own are not cancelled.
     *
     * @return Collection<string, ?Team> keyed by Stripe subscription id
     */
    private function subscriptionsToCancel(User $user): Collection
    {
        if (! isCloud()) {
            return collect();
        }

        $ownedTeams = $user->teams()
            ->where('teams.id', '!=', 0)
            ->wherePivot('role', 'owner')
            ->with('subscriptions')
            ->get();

        $subscriptions = collect();
        foreach ($ownedTeams as $team) {
            foreach ($team->subscriptions->whereNotNull('stripe_subscription_id') as $subscription) {
                $subscriptions->put($subscription->stripe_subscription_id, $team);
            }
        }

        $customerIds = $ownedTeams->flatMap->subscriptions->pluck('stripe_customer_id');
        foreach (app(FindOpenStripeSubscriptions::class)->handle([$user->email], $customerIds) as $subscriptionId => $stripeSubscription) {
            if ($subscriptions->has($subscriptionId)) {
                continue;
            }

            $teamId = FindOpenStripeSubscriptions::teamId($stripeSubscription);
            $team = $teamId === null ? null : Team::find($teamId);
            if ($team && ! $ownedTeams->contains($team)) {
                continue;
            }

            $subscriptions->put($subscriptionId, $team);
        }

        return $subscriptions;
    }

    /**
     * The Profile page renders this on each request, so the Stripe lookup is cached for a short time.
     * handle() looks up the subscriptions again. The subscriptions of $cancelledTeam are left out,
     * because the calling action announces that cancellation itself.
     *
     * @return Collection<int, string>
     */
    private function subscriptionConfirmationActions(User $user, ?Team $cancelledTeam = null): Collection
    {
        if (! isCloud()) {
            return collect();
        }

        return collect(Cache::remember("account-deletion-subscriptions:{$user->id}:{$cancelledTeam?->id}", 60, function () use ($user, $cancelledTeam): array {
            try {
                return $this->subscriptionsToCancel($user)
                    ->reject(fn (?Team $team): bool => $team !== null && $team->is($cancelledTeam))
                    ->map(fn (?Team $team, string $subscriptionId): string => $team
                        ? "The subscription of the team \"{$team->name}\" will be cancelled immediately. This is required."
                        : "The Stripe subscription {$subscriptionId} of your email, which is not linked to a team, will be cancelled immediately. This is required.")
                    ->unique()
                    ->values()
                    ->all();
            } catch (\Throwable $e) {
                // Cached too, so a Stripe outage does not call Stripe and report on every render.
                report($e);

                return ['Every subscription of your teams and your email will be cancelled immediately. This is required.'];
            }
        }));
    }
}
