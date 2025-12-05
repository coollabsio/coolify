<?php

namespace App\Http\Middleware;

use App\Models\UserRecentVisit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackRecentVisits
{
    /**
     * Routes/patterns to skip tracking.
     */
    private array $skipPatterns = [
        'api/*',
        'login',
        'logout',
        'register',
        'forgot-password',
        'reset-password',
        'verify*',
        'auth/*',
        'livewire/*',
        'realtime',
        'terminal/auth*',
        'upload/*',
        'download/*',
        'invitations/*',
    ];

    /**
     * Route patterns that should be tracked with their type and icon.
     */
    private array $trackableRoutes = [
        'project.show' => ['type' => 'project', 'icon' => 'project'],
        'project.resource.index' => ['type' => 'environment', 'icon' => 'environment'],
        'project.application.configuration' => ['type' => 'application', 'icon' => 'application'],
        'project.application.logs' => ['type' => 'application', 'icon' => 'logs', 'subtitle' => 'Logs'],
        'project.application.deployment.index' => ['type' => 'application', 'icon' => 'deployment', 'subtitle' => 'Deployments'],
        'project.database.configuration' => ['type' => 'database', 'icon' => 'database'],
        'project.database.logs' => ['type' => 'database', 'icon' => 'logs', 'subtitle' => 'Logs'],
        'project.service.configuration' => ['type' => 'service', 'icon' => 'service'],
        'project.service.logs' => ['type' => 'service', 'icon' => 'logs', 'subtitle' => 'Logs'],
        'server.show' => ['type' => 'server', 'icon' => 'server'],
        'server.resources' => ['type' => 'server', 'icon' => 'server', 'subtitle' => 'Resources'],
        'server.proxy' => ['type' => 'server', 'icon' => 'proxy', 'subtitle' => 'Proxy'],
        'server.proxy.logs' => ['type' => 'server', 'icon' => 'logs', 'subtitle' => 'Proxy Logs'],
        'server.command' => ['type' => 'server', 'icon' => 'terminal', 'subtitle' => 'Terminal'],
    ];

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    /**
     * Handle tasks after the response has been sent to the browser.
     */
    public function terminate(Request $request, Response $response): void
    {
        // Only track for authenticated users
        if (! auth()->check()) {
            return;
        }

        // Only track successful GET requests
        if (! $request->isMethod('GET') || $response->getStatusCode() >= 400) {
            return;
        }

        // Skip non-trackable patterns
        foreach ($this->skipPatterns as $pattern) {
            if ($request->is($pattern)) {
                return;
            }
        }

        // Get route name
        $routeName = $request->route()?->getName();
        if (! $routeName) {
            return;
        }

        // Check if this route should be tracked
        if (! isset($this->trackableRoutes[$routeName])) {
            return;
        }

        $config = $this->trackableRoutes[$routeName];
        $title = $this->extractTitle($request, $config['type']);
        $subtitle = $config['subtitle'] ?? $this->extractSubtitle($routeName);

        if (! $title) {
            return;
        }

        try {
            $userId = auth()->id();

            UserRecentVisit::recordVisit(
                userId: $userId,
                url: $request->path(),
                title: $title,
                type: $config['type'],
                subtitle: $subtitle,
                icon: $config['icon']
            );

            // Cleanup old visits (keep last 10)
            UserRecentVisit::cleanupOldVisits($userId, 10);
        } catch (\Throwable $e) {
            // Silently fail - don't break the app for tracking
            report($e);
        }
    }

    /**
     * Extract a meaningful title from the request.
     */
    private function extractTitle(Request $request, string $type): ?string
    {
        $route = $request->route();

        return match ($type) {
            'project' => $this->getProjectName($route),
            'environment' => $this->getEnvironmentName($route),
            'application' => $this->getApplicationName($route),
            'database' => $this->getDatabaseName($route),
            'service' => $this->getServiceName($route),
            'server' => $this->getServerName($route),
            default => null,
        };
    }

    /**
     * Extract subtitle from route name.
     */
    private function extractSubtitle(string $routeName): ?string
    {
        $parts = explode('.', $routeName);
        $lastPart = end($parts);

        return match ($lastPart) {
            'configuration' => 'Configuration',
            'logs' => 'Logs',
            'deployment' => 'Deployments',
            'show' => null,
            default => ucfirst($lastPart),
        };
    }

    private function getProjectName($route): ?string
    {
        $projectUuid = $route->parameter('project_uuid');
        if (! $projectUuid) {
            return null;
        }

        $project = \App\Models\Project::where('uuid', $projectUuid)->first();

        return $project?->name;
    }

    private function getEnvironmentName($route): ?string
    {
        $projectUuid = $route->parameter('project_uuid');
        $environmentUuid = $route->parameter('environment_uuid');

        if (! $projectUuid || ! $environmentUuid) {
            return null;
        }

        $project = \App\Models\Project::where('uuid', $projectUuid)->first();
        $environment = \App\Models\Environment::where('uuid', $environmentUuid)->first();

        if ($project && $environment) {
            return $project->name.' / '.$environment->name;
        }

        return $project?->name;
    }

    private function getApplicationName($route): ?string
    {
        $applicationUuid = $route->parameter('application_uuid');
        if (! $applicationUuid) {
            return null;
        }

        $application = \App\Models\Application::where('uuid', $applicationUuid)->first();

        return $application?->name;
    }

    private function getDatabaseName($route): ?string
    {
        $databaseUuid = $route->parameter('database_uuid');
        if (! $databaseUuid) {
            return null;
        }

        // Try different database types
        $database = \App\Models\StandalonePostgresql::where('uuid', $databaseUuid)->first()
            ?? \App\Models\StandaloneMysql::where('uuid', $databaseUuid)->first()
            ?? \App\Models\StandaloneMariadb::where('uuid', $databaseUuid)->first()
            ?? \App\Models\StandaloneMongodb::where('uuid', $databaseUuid)->first()
            ?? \App\Models\StandaloneRedis::where('uuid', $databaseUuid)->first();

        return $database?->name;
    }

    private function getServiceName($route): ?string
    {
        $serviceUuid = $route->parameter('service_uuid');
        if (! $serviceUuid) {
            return null;
        }

        $service = \App\Models\Service::where('uuid', $serviceUuid)->first();

        return $service?->name;
    }

    private function getServerName($route): ?string
    {
        $serverUuid = $route->parameter('server_uuid');
        if (! $serverUuid) {
            return null;
        }

        $server = \App\Models\Server::where('uuid', $serverUuid)->first();

        return $server?->name;
    }
}
