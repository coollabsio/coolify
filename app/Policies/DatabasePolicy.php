<?php

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Models\Environment;
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
        if ($this->isInstanceDatabase($database)) {
            return $user->teams->contains('id', 0);
        }

        return $user->canAccess(AccessLevel::Read, $database);
    }

    /**
     * Determine whether the user can create models, in the environment when one is given.
     */
    public function create(User $user, ?Environment $environment = null): bool
    {
        return app(ResourceCreatePolicy::class)->createAny($user, $environment);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, $database): Response
    {
        if ($this->allows($user, AccessLevel::Write, $database)) {
            return Response::allow();
        }

        return Response::deny('You need at least admin or owner permissions to update this database.');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, $database): bool
    {
        return $this->allows($user, AccessLevel::Delete, $database);
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
     * Determine whether the user can start, stop, restart, and flush the database.
     * Needs write access because flushing deletes the data.
     */
    public function manage(User $user, $database): bool
    {
        return $this->allows($user, AccessLevel::Write, $database);
    }

    /**
     * Determine whether the user can upload a backup archive for this database.
     */
    public function uploadBackup(User $user, $database): Response
    {
        if ($this->allows($user, AccessLevel::Write, $database)) {
            return Response::allow();
        }

        return Response::deny('You need at least admin or owner permissions to upload backups for this database.');
    }

    /**
     * Determine whether the user can manage database backups.
     */
    public function manageBackups(User $user, $database): bool
    {
        return $this->allows($user, AccessLevel::Write, $database);
    }

    /**
     * Determine whether the user can read secret values, such as environment variable values.
     */
    public function viewSensitive(User $user, $database): bool
    {
        return $this->allows($user, AccessLevel::ReadSensitive, $database);
    }

    /**
     * Determine whether the user can manage environment variables.
     */
    public function manageEnvironment(User $user, $database): bool
    {
        return $this->allows($user, AccessLevel::Write, $database);
    }

    /**
     * The instance database (coolify-db) belongs to the root team; only its owners and admins can change it.
     */
    private function allows(User $user, AccessLevel $level, $database): bool
    {
        if ($this->isInstanceDatabase($database)) {
            return $user->isAdminOfTeam(0);
        }

        return $user->canAccess($level, $database);
    }

    private function isInstanceDatabase($database): bool
    {
        return isset($database->id) && $database->id === 0;
    }
}
