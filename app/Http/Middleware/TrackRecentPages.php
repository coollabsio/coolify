<?php

namespace App\Http\Middleware;

use App\Events\RecentsUpdated;
use App\Models\Application;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\Server;
use App\Models\Service;
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

            $this->trackVisit($request);
        }

        return $response;
    }

    protected function trackVisit(Request $request): void
    {
        $route = $request->route();
        if (! $route || ! $route->getName()) {
            return;
        }

        $labelData = $this->deriveLabelAndSublabel($request, $route);
        if (! $labelData['label']) {
            return;
        }

        $user = auth()->user();
        if (! $user || $user->id === null) {
            return;
        }

        $team = $user->currentTeam();
        if (! $team) {
            return;
        }

        $path = $request->path();
        if ($path === '' || $path === '/') {
            $path = 'dashboard';
        }

        UserRecentPage::recordVisit(
            $user->id,
            $team->id,
            $path,
            $labelData['label'],
            $labelData['sublabel']
        );

        // Broadcast event to update all user's browser sessions (delay allows WebSocket to connect first)
        $userId = $user->id;
        dispatch(function () use ($userId) {
            RecentsUpdated::dispatch($userId);
        })->delay(now()->addSeconds(2));
    }

    protected function deriveLabelAndSublabel(Request $request, $route): array
    {
        $routeName = $route->getName();
        $label = null;
        $sublabel = null;

        // Application routes
        if (str_starts_with($routeName, 'project.application')) {
            $uuid = $request->route('application_uuid');
            if ($uuid) {
                $app = Application::where('uuid', $uuid)->first();
                $label = $app?->name;
                $sublabel = $this->deriveSublabelFromRoute($routeName, 'project.application.');
            }

            return ['label' => $label, 'sublabel' => $sublabel];
        }

        // Database routes
        if (str_starts_with($routeName, 'project.database')) {
            $uuid = $request->route('database_uuid');
            if ($uuid) {
                $resource = queryResourcesByUuid($uuid);
                $label = $resource?->name;
                $sublabel = $this->deriveSublabelFromRoute($routeName, 'project.database.');
            }

            return ['label' => $label, 'sublabel' => $sublabel];
        }

        // Service routes
        if (str_starts_with($routeName, 'project.service')) {
            $uuid = $request->route('service_uuid');
            if ($uuid) {
                $service = Service::where('uuid', $uuid)->first();
                $label = $service?->name;
                $sublabel = $this->deriveSublabelFromRoute($routeName, 'project.service.');
            }

            return ['label' => $label, 'sublabel' => $sublabel];
        }

        // Server routes
        if (str_starts_with($routeName, 'server.')) {
            $uuid = $request->route('server_uuid');
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
            $uuid = $request->route('project_uuid');
            if ($uuid) {
                $project = Project::where('uuid', $uuid)->first();
                $label = $project?->name;
            }

            return ['label' => $label, 'sublabel' => null];
        }

        // Storage routes
        if (str_starts_with($routeName, 'storage.')) {
            $uuid = $request->route('storage_uuid');
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
                $uuid = $request->route('private_key_uuid');
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
            $uuid = $request->route('destination_uuid');
            if ($uuid) {
                $destination = \App\Models\StandaloneDocker::where('uuid', $uuid)->first()
                    ?? \App\Models\SwarmDocker::where('uuid', $uuid)->first();
                $label = 'Destinations';
                $sublabel = $destination?->name;
            }

            return ['label' => $label, 'sublabel' => $sublabel];
        }

        // GitHub source routes
        if ($routeName === 'source.github.show') {
            $uuid = $request->route('github_app_uuid');
            if ($uuid) {
                $githubApp = \App\Models\GithubApp::where('uuid', $uuid)->first();
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
            $sublabel = $this->deriveSharedVariablesSublabel($routeName, $request);

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

    protected function deriveSharedVariablesSublabel(string $routeName, Request $request): ?string
    {
        return match ($routeName) {
            'shared-variables.index' => null,
            'shared-variables.team.index' => 'Team',
            'shared-variables.project.index' => 'Projects',
            'shared-variables.project.show' => Project::where('uuid', $request->route('project_uuid'))->first()?->name,
            'shared-variables.environment.index' => 'Environments',
            'shared-variables.environment.show' => Project::where('uuid', $request->route('project_uuid'))->first()?->name,
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
