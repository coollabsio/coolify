<?php

namespace App\Actions\User;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class RevokeUserTeamTokens
{
    public static function forUserTeam(User|int $user, int|string $teamId): int
    {
        return self::baseQuery()
            ->where('tokenable_id', self::userId($user))
            ->where('team_id', $teamId)
            ->delete();
    }

    public static function forUser(User|int $user): int
    {
        return self::baseQuery()
            ->where('tokenable_id', self::userId($user))
            ->delete();
    }

    public static function forTeam(int|string $teamId): int
    {
        return self::baseQuery()
            ->where('team_id', $teamId)
            ->delete();
    }

    private static function baseQuery(): Builder
    {
        return PersonalAccessToken::query()
            ->where('tokenable_type', User::class);
    }

    private static function userId(User|int $user): int
    {
        return $user instanceof User ? $user->id : $user;
    }
}
