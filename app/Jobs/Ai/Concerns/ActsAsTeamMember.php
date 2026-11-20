<?php

namespace App\Jobs\Ai\Concerns;

use App\Ai\Support\AssistantActor;
use App\Models\User;
use App\Services\Access\AccessResolver;
use Illuminate\Support\Facades\Auth;

/**
 * The assistant runs inside a queued job, so it has no HTTP session or Sanctum
 * token. The reused MCP read tools resolve the acting user and team from
 * `auth()->user()` + `currentTeam()` and only accept a tokenless user that carries
 * the hidden assistant marker; this establishes all three for the turn and
 * clears them afterwards so nothing leaks into the next job on the worker.
 */
trait ActsAsTeamMember
{
    protected function actAsTeamMember(int $userId, int $teamId): void
    {
        Auth::setUser(User::findOrFail($userId));
        session(['currentTeam' => ['id' => $teamId]]);
        AssistantActor::set($userId);
        app(AccessResolver::class)->flush();
    }

    protected function clearTeamMemberContext(): void
    {
        session()->forget('currentTeam');
        AssistantActor::clear();
        Auth::forgetGuards();
        app(AccessResolver::class)->flush();
    }
}
