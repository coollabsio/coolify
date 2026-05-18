<?php

namespace App\Policies;

use App\Models\KubernetesCluster;
use App\Models\User;

class KubernetesClusterPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, KubernetesCluster $kubernetesCluster): bool
    {
        return $user->teams->contains('id', $kubernetesCluster->server->team_id);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, KubernetesCluster $kubernetesCluster): bool
    {
        return $user->teams->contains('id', $kubernetesCluster->server->team_id);
    }

    public function delete(User $user, KubernetesCluster $kubernetesCluster): bool
    {
        return $user->teams->contains('id', $kubernetesCluster->server->team_id);
    }

    public function restore(User $user, KubernetesCluster $kubernetesCluster): bool
    {
        return false;
    }

    public function forceDelete(User $user, KubernetesCluster $kubernetesCluster): bool
    {
        return false;
    }
}
