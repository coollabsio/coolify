<?php

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Models\Node;
use App\Models\User;
use App\Services\Access\AccessResolver;
use Illuminate\Auth\Access\Response;

class NodePolicy
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
    public function view(User $user, Node $node): bool
    {
        return app(AccessResolver::class)->allowsTeamWide($user, AccessLevel::Read, $node->team_id);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can place a cluster resource on the Node: move one there or restore a volume there.
     * Like ServerPolicy::placeResource: team-wide write, or write on a cluster resource that already runs there.
     * The Node pages stay team-wide, as the v4 server pages.
     */
    public function placeResource(User $user, Node $node): Response
    {
        $resolver = app(AccessResolver::class);

        return $resolver->allowsTeamWide($user, AccessLevel::Write, (int) $node->team_id) || $resolver->allowsOnNode($user, AccessLevel::Write, $node)
            ? Response::allow()
            : Response::deny('You need write access on the whole team, or on a resource that already runs on this server, to place resources on it.');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Node $node): bool
    {
        return $user->isAdminOfTeam($node->team_id);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Node $node): bool
    {
        return $user->isAdminOfTeam($node->team_id);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Node $node): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Node $node): bool
    {
        return false;
    }

    public function manageSentinel(User $user, Node $node): bool
    {
        return $user->isAdminOfTeam($node->team_id);
    }
}
