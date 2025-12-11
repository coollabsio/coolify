<?php

namespace App\Http\Middleware;

use App\Events\RecentsUpdated;
use App\Models\Application;
use App\Models\GithubApp;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use App\Models\UserRecentPage;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackRecentPages
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Only track GET requests for authenticated users with successful HTML responses
        if ($request->isMethod('GET') &&
            auth()->check() &&
            $response->isSuccessful() &&
            ! $request->ajax() &&
            ! $request->wantsJson()) {

            // Capture what we need before the request ends
            $route = $request->route();
            $routeName = $route?->getName();
            $routeParams = $route?->parameters() ?? [];
            $path = $request->path();
            $userId = auth()->id();
            $teamId = auth()->user()?->currentTeam()?->id;
            if (isset($routeName) && isset($userId) && isset($teamId)) {
                // Defer all tracking work to after the response is sent (zero impact on page load)
                dispatch(function () use ($routeName, $routeParams, $path, $userId, $teamId) {
                    $this->trackVisitDeferred($routeName, $routeParams, $path, $userId, $teamId);
                })->afterResponse();
            }
        }

        return $response;
    }

    protected function trackVisitDeferred(string $routeName, array $routeParams, string $path, int $userId, int $teamId): void
    {
        $labelData = $this->deriveLabelAndSublabel($routeName, $routeParams);
        if (! $labelData['label']) {
            return;
        }

        if ($path === '' || $path === '/') {
            $path = 'dashboard';
        }

        UserRecentPage::recordVisit(
            $userId,
            $teamId,
            $path,
            $labelData['label'],
            $labelData['sublabel']
        );

        // Broadcast event to update all user's browser sessions
        RecentsUpdated::dispatch($userId);
    }

    protected function deriveLabelAndSublabel(string $routeName, array $routeParams): array
    {
        $label = null;
        $sublabel = null;

        // Application routes
        if (str_starts_with($routeName, 'project.application')) {
            $uuid = $routeParams['application_uuid'] ?? null;
            if ($uuid) {
                $app = Application::where('uuid', $uuid)->first();
                $label = $app?->name;
                $sublabel = $this->deriveSublabelFromRoute($routeName, 'project.application.');
            }

            return ['label' => $label, 'sublabel' => $sublabel];
        }

        // Database routes
        if (str_starts_with($routeName, 'project.database')) {
            $uuid = $routeParams['database_uuid'] ?? null;
            if ($uuid) {
                $resource = queryResourcesByUuid($uuid);
                $label = $resource?->name;
                $sublabel = $this->deriveSublabelFromRoute($routeName, 'project.database.');
            }

            return ['label' => $label, 'sublabel' => $sublabel];
        }

        // Service routes
        if (str_starts_with($routeName, 'project.service')) {
            $uuid = $routeParams['service_uuid'] ?? null;
            if ($uuid) {
                $service = Service::where('uuid', $uuid)->first();
                $label = $service?->name;
                $sublabel = $this->deriveSublabelFromRoute($routeName, 'project.service.');
            }

            return ['label' => $label, 'sublabel' => $sublabel];
        }

        // Server routes
        if (str_starts_with($routeName, 'server.')) {
            $uuid = $routeParams['server_uuid'] ?? null;
            if ($uuid) {
                $server = Server::where('uuid', $uuid)->first();
                $label = $server?->name;
                $sublabel = $this->deriveSublabelFromRoute($routeName, 'server.');
            }

            return ['label' => $label, 'sublabel' => $sublabel];
        }

        // Project/Environment routes
        if (str_starts_with($routeName, 'project.resource') ||
            str_starts_with($routeName, 'project.environment') ||
            $routeName === 'project.show' ||
            $routeName === 'project.edit' ||
            $routeName === 'project.clone-me') {
            $uuid = $routeParams['project_uuid'] ?? null;
            if ($uuid) {
                $project = Project::where('uuid', $uuid)->first();
                $label = $project?->name;
            }

            return ['label' => $label, 'sublabel' => null];
        }

        // Storage show route (specific storage)
        if ($routeName === 'storage.show') {
            $uuid = $routeParams['storage_uuid'] ?? null;
            if ($uuid) {
                $storage = S3Storage::where('uuid', $uuid)->first();
                $label = 'S3 Storages';
                $sublabel = $storage?->name;
            }

            return ['label' => $label, 'sublabel' => $sublabel];
        }

        // Security (Keys & Tokens) routes
        if (str_starts_with($routeName, 'security.')) {
            $label = 'Keys & Tokens';

            // Private key detail page - show key name as sublabel
            if ($routeName === 'security.private-key.show') {
                $uuid = $routeParams['private_key_uuid'] ?? null;
                if ($uuid) {
                    $privateKey = PrivateKey::where('uuid', $uuid)->first();
                    $sublabel = $privateKey?->name;
                }

                return ['label' => $label, 'sublabel' => $sublabel];
            }

            // Other security sub-pages
            $sublabel = match ($routeName) {
                'security.private-key.index' => 'Private Keys',
                'security.api-tokens' => 'API Tokens',
                'security.cloud-tokens' => 'Cloud Tokens',
                'security.cloud-init-scripts' => 'Cloud Init Scripts',
                default => null,
            };

            return ['label' => $label, 'sublabel' => $sublabel];
        }

        // Destination show route
        if ($routeName === 'destination.show') {
            $uuid = $routeParams['destination_uuid'] ?? null;
            if ($uuid) {
                $destination = StandaloneDocker::where('uuid', $uuid)->first()
                    ?? SwarmDocker::where('uuid', $uuid)->first();
                $label = 'Destinations';
                $sublabel = $destination?->name;
            }

            return ['label' => $label, 'sublabel' => $sublabel];
        }

        // GitHub source routes
        if ($routeName === 'source.github.show') {
            $uuid = $routeParams['github_app_uuid'] ?? null;
            if ($uuid) {
                $githubApp = GithubApp::where('uuid', $uuid)->first();
                $label = 'Sources';
                $sublabel = $githubApp?->name;
            }

            return ['label' => $label, 'sublabel' => $sublabel];
        }

        // Settings sub-routes
        if (str_starts_with($routeName, 'settings.')) {
            $label = 'Settings';
            $sublabel = match ($routeName) {
                'settings.index' => 'General',
                'settings.advanced' => 'Advanced',
                'settings.updates' => 'Updates',
                'settings.backup' => 'Backup',
                'settings.email' => 'Email',
                'settings.oauth' => 'OAuth',
                default => null,
            };

            return ['label' => $label, 'sublabel' => $sublabel];
        }

        // Shared variables sub-routes with project/environment context
        if (str_starts_with($routeName, 'shared-variables.')) {
            $label = 'Shared Variables';
            $sublabel = $this->deriveSharedVariablesSublabel($routeName, $routeParams);

            return ['label' => $label, 'sublabel' => $sublabel];
        }

        // Notifications sub-routes
        if (str_starts_with($routeName, 'notifications.')) {
            $label = 'Notifications';
            $sublabel = match ($routeName) {
                'notifications.email' => 'Email',
                'notifications.telegram' => 'Telegram',
                'notifications.discord' => 'Discord',
                'notifications.slack' => 'Slack',
                'notifications.pushover' => 'Pushover',
                'notifications.webhook' => 'Webhook',
                default => null,
            };

            return ['label' => $label, 'sublabel' => $sublabel];
        }

        // Teams sub-routes
        if (str_starts_with($routeName, 'team.')) {
            $label = 'Teams';
            $sublabel = match ($routeName) {
                'team.index' => 'Settings',
                'team.member.index' => 'Members',
                'team.admin-view' => 'Admin View',
                default => null,
            };

            return ['label' => $label, 'sublabel' => $sublabel];
        }

        // Static page labels (exclude dashboard - it's always visible in navbar)
        $label = match ($routeName) {
            'project.index' => 'Projects',
            'server.index' => 'Servers',
            'source.all' => 'Sources',
            'destination.index' => 'Destinations',
            'storage.index' => 'S3 Storages',
            'tags.show' => 'Tags',
            'profile' => 'Profile',
            'terminal' => 'Terminal',
            'subscription.show', 'subscription.index' => 'Subscription',
            default => null,
        };

        return ['label' => $label, 'sublabel' => null];
    }

    protected function deriveSharedVariablesSublabel(string $routeName, array $routeParams): ?string
    {
        $projectUuid = $routeParams['project_uuid'] ?? null;
        $projectName = $projectUuid ? Project::where('uuid', $projectUuid)->value('name') : null;

        return match ($routeName) {
            'shared-variables.index' => null,
            'shared-variables.team.index' => 'Team',
            'shared-variables.project.index' => 'Projects',
            'shared-variables.project.show' => $projectName,
            'shared-variables.environment.index' => 'Environments',
            'shared-variables.environment.show' => $projectName,
            default => null,
        };
    }

    protected function deriveSublabelFromRoute(string $routeName, string $prefix): ?string
    {
        // Remove the prefix to get the suffix (e.g., "project.application.terminal" -> "terminal")
        $suffix = str_replace($prefix, '', $routeName);

        // Skip base routes (e.g., just "project.application" with no suffix after the uuid segment)
        if ($suffix === '' || $suffix === 'show' || $suffix === 'index') {
            return null;
        }

        // Map route suffixes to human-readable sublabels
        return match ($suffix) {
            // Common sub-routes
            'terminal', 'command' => 'Terminal',
            'logs' => 'Logs',
            'configuration' => 'Configuration',
            'environment-variables' => 'Environment Variables',
            'storages' => 'Storages',
            'webhooks' => 'Webhooks',
            'tags' => 'Tags',
            'metrics', 'charts' => 'Metrics',
            'servers' => 'Servers',

            // Application-specific
            'deployment.index', 'deployment.show' => 'Deployments',
            'swarm' => 'Swarm',
            'advanced' => 'Advanced',
            'persistent-storage' => 'Persistent Storage',
            'source' => 'Source',
            'scheduled-tasks', 'scheduled-tasks.show' => 'Scheduled Tasks',
            'preview-deployments' => 'Preview Deployments',
            'healthcheck' => 'Healthcheck',
            'rollback' => 'Rollback',
            'resource-limits' => 'Resource Limits',
            'resource-operations' => 'Resource Operations',
            'danger' => 'Danger Zone',

            // Database-specific
            'import-backups' => 'Import Backups',
            'backup.index', 'backup.execution' => 'Backups',

            // Server-specific
            'private-key' => 'Private Key',
            'cloud-provider-token' => 'Cloud Provider Token',
            'ca-certificate' => 'CA Certificate',
            'cloudflare-tunnel' => 'Cloudflare Tunnel',
            'destinations' => 'Destinations',
            'resources' => 'Resources',
            'log-drains' => 'Log Drains',
            'delete' => 'Delete',
            'docker-cleanup' => 'Docker Cleanup',
            'proxy' => 'Proxy',
            'proxy.dynamic-confs' => 'Dynamic Configurations',
            'proxy.logs' => 'Proxy Logs',
            'security.patches' => 'Security Patches',
            'security.terminal-access' => 'Terminal Access',

            // Settings-specific
            'updates' => 'Updates',
            'backup' => 'Backup',
            'email' => 'Email',
            'oauth' => 'OAuth',

            default => null,
        };
    }
}
