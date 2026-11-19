<?php

namespace App\Http\Middleware;

use App\Models\Environment;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class CanCreateResources
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $environment = null;
        if ($request->route('environment_uuid')) {
            $environment = Environment::query()
                ->where('uuid', $request->route('environment_uuid'))
                ->whereRelation('project', fn ($query) => $query
                    ->where('uuid', $request->route('project_uuid'))
                    ->where('team_id', currentTeam()?->id))
                ->first();
            if ($environment === null) {
                // The page redirects to the dashboard for an environment outside the current team.
                return $next($request);
            }
        }

        if (! Gate::allows('createAnyResource', [$environment])) {
            abort(403, 'You do not have permission to create resources.');
        }

        return $next($request);
    }
}
