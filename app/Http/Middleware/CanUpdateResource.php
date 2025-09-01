<?php

namespace App\Http\Middleware;

use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class CanUpdateResource
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);

        // Get resource from route parameters
        // $resource = null;
        // if ($request->route('application_uuid')) {
        //     $resource = Application::where('uuid', $request->route('application_uuid'))->first();
        // } elseif ($request->route('service_uuid')) {
        //     $resource = Service::where('uuid', $request->route('service_uuid'))->first();
        // } elseif ($request->route('stack_service_uuid')) {
        //     // Handle ServiceApplication or ServiceDatabase
        //     $stack_service_uuid = $request->route('stack_service_uuid');
        //     $resource = ServiceApplication::where('uuid', $stack_service_uuid)->first() ??
        //                ServiceDatabase::where('uuid', $stack_service_uuid)->first();
        // } elseif ($request->route('database_uuid')) {
        //     // Try different database types
        //     $database_uuid = $request->route('database_uuid');
        //     $resource = StandalonePostgresql::where('uuid', $database_uuid)->first() ??
        //                StandaloneMysql::where('uuid', $database_uuid)->first() ??
        //                StandaloneMariadb::where('uuid', $database_uuid)->first() ??
        //                StandaloneRedis::where('uuid', $database_uuid)->first() ??
        //                StandaloneKeydb::where('uuid', $database_uuid)->first() ??
        //                StandaloneDragonfly::where('uuid', $database_uuid)->first() ??
        //                StandaloneClickhouse::where('uuid', $database_uuid)->first() ??
        //                StandaloneMongodb::where('uuid', $database_uuid)->first();
        // } elseif ($request->route('server_uuid')) {
        //     // For server routes, check if user can manage servers
        //     if (! auth()->user()->isAdmin()) {
        //         abort(403, 'You do not have permission to access this resource.');
        //     }

        //     return $next($request);
        // } elseif ($request->route('environment_uuid')) {
        //     $resource = Environment::where('uuid', $request->route('environment_uuid'))->first();
        // } elseif ($request->route('project_uuid')) {
        //     $resource = Project::ownedByCurrentTeam()->where('uuid', $request->route('project_uuid'))->first();
        // }

        // if (! $resource) {
        //     abort(404, 'Resource not found.');
        // }

        // if (! Gate::allows('update', $resource)) {
        //     abort(403, 'You do not have permission to update this resource.');
        // }

        // return $next($request);
    }
}
