<?php

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Models\ScheduledTask;
use App\Models\User;
use App\Services\Access\AccessResolver;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/**
 * A scheduled task is part of its application's or service's configuration: reading it needs view on that
 * resource, every change needs update (write access) on it. A task without a visible parent needs team-wide access.
 */
class ScheduledTaskPolicy
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
    public function view(User $user, ScheduledTask $scheduledTask): bool
    {
        $resource = $this->resource($scheduledTask);
        if ($resource === null) {
            return app(AccessResolver::class)->allowsTeamWide($user, AccessLevel::Read, $scheduledTask->team_id);
        }

        return Gate::forUser($user)->allows('view', $resource);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, ScheduledTask $scheduledTask): Response
    {
        if (! $this->canChange($user, $scheduledTask)) {
            return Response::deny('You need at least admin or owner permissions to update this scheduled task.');
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ScheduledTask $scheduledTask): bool
    {
        return $this->canChange($user, $scheduledTask);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, ScheduledTask $scheduledTask): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, ScheduledTask $scheduledTask): bool
    {
        return false;
    }

    private function canChange(User $user, ScheduledTask $scheduledTask): bool
    {
        $resource = $this->resource($scheduledTask);
        if ($resource === null) {
            return $user->isAdminOfTeam($scheduledTask->team_id);
        }

        return Gate::forUser($user)->allows('update', $resource);
    }

    private function resource(ScheduledTask $scheduledTask): ?object
    {
        return $scheduledTask->application ?? $scheduledTask->service;
    }
}
