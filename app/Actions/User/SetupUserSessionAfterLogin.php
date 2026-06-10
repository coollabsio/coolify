<?php

namespace App\Actions\User;

use App\Models\TeamInvitation;
use App\Models\User;

class SetupUserSessionAfterLogin
{
    public static function run(User $user): void
    {
        $user->loadMissing('teams');

        $invitation = TeamInvitation::whereEmail($user->email)->first();
        if ($invitation && $invitation->isValid()) {
            if (! $user->teams()->where('team_id', $invitation->team->id)->exists()) {
                $user->teams()->attach($invitation->team->id, ['role' => $invitation->role]);
            }
            $user->currentTeam = $invitation->team;
            $invitation->delete();
        } else {
            $user->currentTeam = $user->teams->firstWhere('personal_team', true);
            if (! $user->currentTeam) {
                $user->currentTeam = $user->recreate_personal_team();
            }
        }

        session(['currentTeam' => $user->currentTeam]);
    }
}
