<?php

namespace App\Jobs\Ai\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * The assistant runs inside a queued job, so it has no HTTP session or Sanctum
 * token. The reused MCP read tools resolve the acting user and team from
 * `auth()->user()` + `currentTeam()`; this establishes both for the turn and
 * clears them afterwards so nothing leaks into the next job on the worker.
 */
trait ActsAsTeamMember
{
    protected function actAsTeamMember(int $userId, int $teamId): void
    {
        Auth::setUser(User::findOrFail($userId));
        session(['currentTeam' => ['id' => $teamId]]);
    }

    protected function clearTeamMemberContext(): void
    {
        session()->forget('currentTeam');
        Auth::forgetGuards();
    }
}
