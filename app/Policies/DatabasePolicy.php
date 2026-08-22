<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

class DatabasePolicy
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
    public function view(User $user, $database): bool
    {
        $teamId = $this->getTeamId($database);

        return $teamId !== null && $user->teams->contains('id', $teamId);
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
    public function update(User $user, $database): Response
    {
        $teamId = $this->getTeamId($database);

        if ($teamId === null) {
            return Response::deny('Database team not found.');
        }

        if ($user->isAdminOfTeam($teamId)) {
            return Response::allow();
        }

        return Response::deny('You need at least admin or owner permissions to update this database.');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, $database): bool
    {
        $teamId = $this->getTeamId($database);

        return $teamId !== null && $user->isAdminOfTeam($teamId);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, $database): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, $database): bool
    {
        return false;
    }

    /**
     * Determine whether the user can start/stop the database.
     */
    public function manage(User $user, $database): bool
    {
        $teamId = $this->getTeamId($database);

        return $teamId !== null && $user->isAdminOfTeam($teamId);
    }

    /**
     * Determine whether the user can upload a backup archive for this database.
     */
    public function uploadBackup(User $user, $database): Response
    {
        $teamId = $this->getTeamId($database);

        if ($teamId === null) {
            return Response::deny('Database team not found.');
        }

        if ($user->isAdminOfTeam($teamId)) {
            return Response::allow();
        }

        return Response::deny('You need at least admin or owner permissions to upload backups for this database.');
    }

    /**
     * Determine whether the user can manage database backups.
     */
    public function manageBackups(User $user, $database): bool
    {
        $teamId = $this->getTeamId($database);

        return $teamId !== null && $user->isAdminOfTeam($teamId);
    }

    /**
     * Determine whether the user can manage environment variables.
     */
    public function manageEnvironment(User $user, $database): bool
    {
        $teamId = $this->getTeamId($database);

        return $teamId !== null && $user->isAdminOfTeam($teamId);
    }

    private function getTeamId($database): ?int
    {
        // Instance-level databases (e.g., coolify-db) belong to root team
        if (isset($database->id) && $database->id === 0) {
            return 0;
        }

        if (method_exists($database, 'team')) {
            return $database->team()?->id;
        }

        return null;
    }
}
