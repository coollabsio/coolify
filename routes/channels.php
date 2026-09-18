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

use App\Models\AiConversation;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('team.{teamId}', function (User $user, int $teamId) {
    if ($user->teams->pluck('id')->contains($teamId)) {
        return true;
    }

    return false;
});

Broadcast::channel('user.{userId}', function (User $user) {
    if ($user->id === Auth::id()) {
        return true;
    }

    return false;
});

Broadcast::channel('ai-conversation.{uuid}', function (User $user, string $uuid) {
    $conversation = AiConversation::where('uuid', $uuid)->first();

    return $conversation !== null && $user->can('view', $conversation);
});
