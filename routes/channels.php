<?php

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

use App\Models\Application;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('team.{teamId}', function (User $user, int $teamId) {
    if ($user->teams->pluck('id')->contains($teamId)) {
        return true;
    }

    return false;
});

Broadcast::channel('user.{userId}', function (User $user) {
    if ($user->id === auth()->user()->id) {
        return true;
    }

    return false;
});
