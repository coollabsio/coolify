<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Laravel writes the whole session payload at the end of every request. A request
 * that started before a team switch (for example a wire:poll update) would write the
 * previous team back into the session, and the next request would make it permanent.
 * When this request did not change the team itself, keep the team that is stored now.
 */
class KeepConcurrentTeamSwitch
{
    public function handle(Request $request, Closure $next): Response
    {
        $teamIdAtStart = data_get($request->session()->get('currentTeam'), 'id');

        $response = $next($request);

        $session = $request->session();
        if (data_get($session->get('currentTeam'), 'id') !== $teamIdAtStart) {
            return $response;
        }

        $storedSession = clone $session;
        $storedSession->start();
        $storedTeam = $storedSession->get('currentTeam');
        if ($storedTeam && data_get($storedTeam, 'id') !== $teamIdAtStart) {
            $session->put('currentTeam', $storedTeam);
        }

        return $response;
    }
}
