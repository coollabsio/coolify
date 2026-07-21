<?php

namespace App\Support\Dashboard;

use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Shared dashboard data for the classic Livewire UI and the Next (Inertia) UI.
 *
 * Keep both UIs on the same data source so feature/permission changes land once.
 */
class DashboardData
{
    /**
     * @return array{
     *     projects: Collection<int, Project>,
     *     servers: Collection<int, Server>,
     *     privateKeys: Collection<int, PrivateKey>
     * }
     */
    public static function collections(): array
    {
        return [
            'privateKeys' => PrivateKey::ownedByCurrentTeamCached(),
            'servers' => Server::ownedByCurrentTeamCached(),
            'projects' => Project::ownedByCurrentTeam()->with('environments')->get(),
        ];
    }

    /**
     * JSON-serializable props for the Next (Inertia/React) dashboard.
     *
     * @return array<string, mixed>
     */
    public static function forInertia(): array
    {
        $data = self::collections();
        $user = auth()->user();

        return [
            'projects' => $data['projects']->map(function (Project $project) use ($user): array {
                $firstEnvironment = $project->environments->first();

                return [
                    'uuid' => $project->uuid,
                    'name' => $project->name,
                    'description' => $project->description,
                    'url' => $project->navigateTo(),
                    'firstEnvironmentUuid' => $firstEnvironment?->uuid,
                    'canUpdate' => $user?->can('update', $project) ?? false,
                    'resourceCreateUrl' => $firstEnvironment
                        ? route('project.resource.create', [
                            'project_uuid' => $project->uuid,
                            'environment_uuid' => $firstEnvironment->uuid,
                        ])
                        : null,
                    'settingsUrl' => route('project.edit', ['project_uuid' => $project->uuid]),
                ];
            })->values()->all(),
            'servers' => $data['servers']->map(function (Server $server): array {
                return [
                    'uuid' => $server->uuid,
                    'name' => $server->name,
                    'description' => $server->description,
                    'url' => route('server.show', ['server_uuid' => $server->uuid]),
                    'isReachable' => (bool) $server->settings?->is_reachable,
                    'isUsable' => (bool) $server->settings?->is_usable,
                    'forceDisabled' => (bool) $server->settings?->force_disabled,
                ];
            })->values()->all(),
            'privateKeysCount' => $data['privateKeys']->count(),
            'permissions' => [
                'createProject' => $user?->can('create', Project::class) ?? false,
                'createServer' => $user?->can('create', Server::class) ?? false,
                'createAnyResource' => Gate::forUser($user)->allows('createAnyResource'),
            ],
            'links' => [
                'onboarding' => route('onboarding'),
                'serverCreate' => route('server.create'),
                'profileAppearance' => route('profile.appearance'),
                'dashboard' => route('dashboard'),
                'uiMode' => route('ui.mode'),
            ],
            'flash' => [
                'error' => session('error'),
            ],
        ];
    }
}
