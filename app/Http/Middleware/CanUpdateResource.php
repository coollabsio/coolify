<?php

namespace App\Http\Middleware;

use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
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
    /**
     * @var array<string, list<class-string>>
     */
    private const ROUTE_RESOURCE_MODELS = [
        'application_uuid' => [Application::class],
        'database_uuid' => [
            StandalonePostgresql::class,
            StandaloneMysql::class,
            StandaloneMariadb::class,
            StandaloneRedis::class,
            StandaloneKeydb::class,
            StandaloneDragonfly::class,
            StandaloneClickhouse::class,
            StandaloneMongodb::class,
        ],
        'stack_service_uuid' => [ServiceApplication::class, ServiceDatabase::class],
        'service_uuid' => [Service::class],
        'server_uuid' => [Server::class],
        'environment_uuid' => [Environment::class],
        'project_uuid' => [Project::class],
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $resource = $this->resourceFromRoute($request);

        if (! $resource) {
            abort(404, 'Resource not found.');
        }

        if (! Gate::allows('update', $resource)) {
            abort(403, 'You do not have permission to update this resource.');
        }

        return $next($request);
    }

    private function resourceFromRoute(Request $request): ?object
    {
        foreach (self::ROUTE_RESOURCE_MODELS as $routeParameter => $models) {
            $uuid = $request->route($routeParameter);

            if (! $uuid) {
                continue;
            }

            foreach ($models as $model) {
                $resource = $model::where('uuid', $uuid)->first();

                if ($resource) {
                    return $resource;
                }
            }
        }

        return null;
    }
}
