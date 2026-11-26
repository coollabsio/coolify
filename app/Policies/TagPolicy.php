<?php

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Models\Tag;
use App\Models\User;
use App\Services\Access\AccessResolver;

class TagPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Tag $tag): bool
    {
        return $user->teams->contains('id', $tag->team_id);
    }

    /**
     * Determine whether the user can create models. Tags are team-wide, so this needs team-wide write.
     */
    public function create(User $user): bool
    {
        $teamId = $user->currentTeam()?->id;

        return $teamId !== null && $this->allowsTeamWideWrite($user, (int) $teamId);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Tag $tag): bool
    {
        return $this->allowsTeamWideWrite($user, (int) $tag->team_id);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Tag $tag): bool
    {
        return $this->allowsTeamWideWrite($user, (int) $tag->team_id);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Tag $tag): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Tag $tag): bool
    {
        return false;
    }

    private function allowsTeamWideWrite(User $user, int $teamId): bool
    {
        return app(AccessResolver::class)->allowsTeamWide($user, AccessLevel::Write, $teamId);
    }
}
