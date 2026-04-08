<?php

namespace App\Services\Notifications;

use App\Actions\Server\CheckUpdates;
use App\Models\Application;
use App\Models\Server;
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
use App\Models\Team;
use App\Services\ContainerImageUpdateDetector;
use Illuminate\Support\Arr;
use Symfony\Component\Yaml\Yaml;

class MasterUpdateReportBuilder
{
    public function __construct(
        protected ContainerImageUpdateDetector $imageUpdateDetector
    ) {}

    public function collect(Team $team): array
    {
        return array_values(array_filter([
            ...$this->collectCoolifyUpdates(),
            ...$this->collectTraefikUpdates($team),
            ...$this->collectServerPatchUpdates($team),
            ...$this->collectContainerImageUpdates($team),
        ]));
    }

    protected function collectCoolifyUpdates(): array
    {
        $settings = instanceSettings();
        $currentVersion = config('constants.coolify.version');
        $latestVersion = get_latest_version_of_coolify();

        if (! data_get($settings, 'new_version_available') || version_compare($latestVersion, $currentVersion, '<=')) {
            return [];
        }

        return [[
            'section' => 'coolify_upgrades',
            'item_type' => 'coolify_upgrade',
            'item_key' => 'instance:coolify',
            'fingerprint' => $this->fingerprint([$currentVersion, $latestVersion]),
            'label' => 'Coolify',
            'summary' => "{$currentVersion} -> {$latestVersion}",
            'url' => $this->toDashboardUrl(route('settings.updates')),
        ]];
    }

    protected function collectTraefikUpdates(Team $team): array
    {
        if (! $team->emailNotificationSettings?->traefik_outdated_email_notifications) {
            return [];
        }

        return $team->servers()
            ->whereNotNull('traefik_outdated_info')
            ->get()
            ->map(function ($server) {
                $info = $server->traefik_outdated_info ?? null;
                if (! is_array($info) || empty($info)) {
                    return null;
                }

                $fingerprintData = Arr::except($info, ['checked_at']);
                $summary = $this->formatTraefikSummary($info);

                return [
                    'section' => 'proxy_upgrades',
                    'item_type' => 'proxy_upgrade',
                    'item_key' => "server:{$server->id}:proxy",
                    'fingerprint' => $this->fingerprint($fingerprintData),
                    'label' => $server->name,
                    'summary' => $summary,
                    'url' => $this->toDashboardUrl(route('server.proxy', ['server_uuid' => $server->uuid])),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    protected function collectServerPatchUpdates(Team $team): array
    {
        if (! $team->emailNotificationSettings?->server_patch_email_notifications) {
            return [];
        }

        $items = [];

        foreach ($team->servers as $server) {
            if (! $server->isFunctional()) {
                continue;
            }

            $patchData = CheckUpdates::run($server);
            if (isset($patchData['error']) || empty($patchData['updates'])) {
                continue;
            }

            foreach ($patchData['updates'] as $update) {
                $items[] = [
                    'section' => 'server_patches',
                    'item_type' => 'server_patch',
                    'item_key' => sprintf(
                        'server:%d:package:%s:%s:%s',
                        $server->id,
                        data_get($update, 'package', 'unknown'),
                        data_get($update, 'repository', 'unknown'),
                        data_get($update, 'architecture', 'unknown')
                    ),
                    'fingerprint' => $this->fingerprint([
                        data_get($update, 'current_version'),
                        data_get($update, 'new_version'),
                    ]),
                    'group_key' => "server:{$server->id}",
                    'group_label' => $server->name,
                    'group_url' => $this->toDashboardUrl(route('server.security.patches', ['server_uuid' => $server->uuid])),
                    'label' => data_get($update, 'package', 'unknown'),
                    'summary' => sprintf(
                        '%s -> %s',
                        data_get($update, 'current_version', 'unknown'),
                        data_get($update, 'new_version', 'unknown')
                    ),
                ];
            }
        }

        return $items;
    }

    protected function collectContainerImageUpdates(Team $team): array
    {
        return [
            ...$this->collectDockerImageApplications($team),
            ...$this->collectComposeApplications($team),
            ...$this->collectServiceApplications($team),
            ...$this->collectServiceDatabases($team),
            ...$this->collectStandaloneDatabaseImages($team, StandalonePostgresql::class, 'Database'),
            ...$this->collectStandaloneDatabaseImages($team, StandaloneRedis::class, 'Database'),
            ...$this->collectStandaloneDatabaseImages($team, StandaloneMysql::class, 'Database'),
            ...$this->collectStandaloneDatabaseImages($team, StandaloneMariadb::class, 'Database'),
            ...$this->collectStandaloneDatabaseImages($team, StandaloneMongodb::class, 'Database'),
            ...$this->collectStandaloneDatabaseImages($team, StandaloneKeydb::class, 'Database'),
            ...$this->collectStandaloneDatabaseImages($team, StandaloneDragonfly::class, 'Database'),
            ...$this->collectStandaloneDatabaseImages($team, StandaloneClickhouse::class, 'Database'),
        ];
    }

    protected function collectDockerImageApplications(Team $team): array
    {
        return Application::whereRelation('environment.project.team', 'id', $team->id)
            ->where('build_pack', 'dockerimage')
            ->whereNotNull('docker_registry_image_name')
            ->with(['environment.project', 'destination.server'])
            ->get()
            ->map(fn ($application) => $this->buildContainerImageItem(
                itemType: 'container_image_update',
                itemKey: "application:{$application->id}",
                label: $this->resourceLabel($application->name, data_get($application, 'environment.project.name'), 'Application'),
                imageReference: $this->applicationImageReference($application),
                url: $this->toDashboardUrl($application->link()),
                server: data_get($application, 'destination.server'),
            ))
            ->filter()
            ->values()
            ->all();
    }

    protected function collectComposeApplications(Team $team): array
    {
        $items = [];

        $applications = Application::whereRelation('environment.project.team', 'id', $team->id)
            ->where('build_pack', 'dockercompose')
            ->whereNotNull('docker_compose_raw')
            ->with(['environment.project', 'destination.server'])
            ->get();

        foreach ($applications as $application) {
            try {
                $services = data_get(Yaml::parse($application->docker_compose_raw), 'services', []);
            } catch (\Throwable) {
                continue;
            }

            foreach ($services as $serviceName => $serviceConfig) {
                $imageReference = data_get($serviceConfig, 'image');
                if (! is_string($imageReference) || blank($imageReference)) {
                    continue;
                }

                $item = $this->buildContainerImageItem(
                    itemType: 'container_image_update',
                    itemKey: "application:{$application->id}:compose:{$serviceName}",
                    label: $this->resourceLabel("{$application->name} / {$serviceName}", data_get($application, 'environment.project.name'), 'Application'),
                    imageReference: $imageReference,
                    url: $this->toDashboardUrl($application->link()),
                    server: data_get($application, 'destination.server'),
                );

                if ($item) {
                    $items[] = $item;
                }
            }
        }

        return $items;
    }

    protected function collectServiceApplications(Team $team): array
    {
        return ServiceApplication::whereRelation('service.environment.project.team', 'id', $team->id)
            ->whereNotNull('image')
            ->with(['service.environment.project', 'service.server'])
            ->get()
            ->map(fn ($application) => $this->buildContainerImageItem(
                itemType: 'container_image_update',
                itemKey: "service_application:{$application->id}",
                label: $this->resourceLabel(data_get($application, 'human_name') ?: $application->name, data_get($application, 'service.environment.project.name'), 'Service'),
                imageReference: $application->image,
                url: $this->toDashboardUrl($application->service?->link()),
                server: data_get($application, 'service.server'),
            ))
            ->filter()
            ->values()
            ->all();
    }

    protected function collectServiceDatabases(Team $team): array
    {
        return ServiceDatabase::whereRelation('service.environment.project.team', 'id', $team->id)
            ->whereNotNull('image')
            ->with(['service.environment.project', 'service.server'])
            ->get()
            ->map(fn ($database) => $this->buildContainerImageItem(
                itemType: 'container_image_update',
                itemKey: "service_database:{$database->id}",
                label: $this->resourceLabel(data_get($database, 'human_name') ?: $database->name, data_get($database, 'service.environment.project.name'), 'Service Database'),
                imageReference: $database->image,
                url: $this->toDashboardUrl($database->service?->link()),
                server: data_get($database, 'service.server'),
            ))
            ->filter()
            ->values()
            ->all();
    }

    protected function collectStandaloneDatabaseImages(Team $team, string $modelClass, string $kind): array
    {
        return $modelClass::whereRelation('environment.project.team', 'id', $team->id)
            ->whereNotNull('image')
            ->with(['environment.project', 'destination.server'])
            ->get()
            ->map(fn ($database) => $this->buildContainerImageItem(
                itemType: 'container_image_update',
                itemKey: class_basename($modelClass).":{$database->id}",
                label: $this->resourceLabel($database->name, data_get($database, 'environment.project.name'), $kind),
                imageReference: $database->image,
                url: $this->toDashboardUrl($database->link()),
                server: data_get($database, 'destination.server'),
            ))
            ->filter()
            ->values()
            ->all();
    }

    protected function buildContainerImageItem(
        string $itemType,
        string $itemKey,
        string $label,
        string $imageReference,
        ?string $url,
        ?Server $server,
    ): ?array {
        $update = $this->imageUpdateDetector->detect($imageReference, $server);
        if (! $update) {
            return null;
        }

        return [
            'section' => 'container_image_updates',
            'item_type' => $itemType,
            'item_key' => $itemKey,
            'fingerprint' => $this->fingerprint([
                data_get($update, 'current_reference'),
                data_get($update, 'target_reference'),
                data_get($update, 'current_digest'),
                data_get($update, 'target_digest'),
            ]),
            'label' => $label,
            'summary' => data_get($update, 'summary'),
            'url' => $url,
        ];
    }

    protected function applicationImageReference(Application $application): string
    {
        $tag = $application->docker_registry_image_tag ?: 'latest';

        return "{$application->docker_registry_image_name}:{$tag}";
    }

    protected function resourceLabel(string $name, ?string $projectName, string $kind): string
    {
        return $projectName
            ? "{$projectName} / {$name} ({$kind})"
            : "{$name} ({$kind})";
    }

    protected function formatTraefikSummary(array $info): string
    {
        $current = data_get($info, 'current', 'unknown');
        $latest = data_get($info, 'latest', 'unknown');
        $type = data_get($info, 'type', 'patch_update');

        if ($type === 'minor_upgrade') {
            $target = data_get($info, 'upgrade_target', $latest);

            return "{$current} -> {$target} (latest patch: {$latest})";
        }

        $summary = "{$current} -> {$latest} (patch update)";
        if (data_get($info, 'newer_branch_target')) {
            $summary .= sprintf(
                '; also available: %s (%s)',
                data_get($info, 'newer_branch_target'),
                data_get($info, 'newer_branch_latest')
            );
        }

        return $summary;
    }

    protected function toDashboardUrl(?string $route): ?string
    {
        if (! $route) {
            return null;
        }

        $parts = parse_url($route);
        $path = data_get($parts, 'path', '');
        $query = data_get($parts, 'query');

        return rtrim(base_url(), '/').$path.($query ? "?{$query}" : '');
    }

    protected function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES));
    }
}
