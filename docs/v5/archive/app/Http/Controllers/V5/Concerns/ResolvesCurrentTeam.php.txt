<?php

namespace App\Http\Controllers\V5\Concerns;

use App\Models\Team;
use Illuminate\Http\Request;

trait ResolvesCurrentTeam
{
    /**
     * Resolve the current team set by the EnsureCurrentTeam middleware, or
     * abort with a 404 so resources outside the team stay invisible.
     */
    protected function currentTeamOrFail(Request $request): Team
    {
        $currentTeam = $request->attributes->get('v5.currentTeam');

        abort_unless($currentTeam instanceof Team, 404);

        return $currentTeam;
    }
}
