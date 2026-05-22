<?php

namespace App\Policies;

use App\Models\GitlabApp;
use App\Models\User;

class GitlabAppPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, GitlabApp $gitlabApp): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, GitlabApp $gitlabApp): bool
    {
        if ($gitlabApp->is_system_wide) {
            return true;
        }

        return true;
    }

    public function delete(User $user, GitlabApp $gitlabApp): bool
    {
        if ($gitlabApp->is_system_wide) {
            return true;
        }

        return true;
    }

    public function restore(User $user, GitlabApp $gitlabApp): bool
    {
        return false;
    }

    public function forceDelete(User $user, GitlabApp $gitlabApp): bool
    {
        return false;
    }
}
