<?php

namespace App\Services\Docker;

use App\Enums\DockerNetworkRole;
use App\Enums\DockerNetworkSourceType;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;

class DockerNetworkClassifier
{
    public const SYSTEM_NETWORK_NAMES = ['bridge', 'host', 'none'];

    public const COOLIFY_DEFAULT_NETWORK_NAMES = ['coolify', 'coolify-overlay'];

    public function classify(Server $server, string $networkName): array
    {
        if (in_array($networkName, self::SYSTEM_NETWORK_NAMES, true)) {
            return $this->system();
        }

        if ($networkName === 'coolify') {
            return $this->standaloneDefault(isSystem: true);
        }

        if ($networkName === 'coolify-overlay') {
            return $this->swarmDefault(isSystem: true);
        }

        $standaloneDocker = StandaloneDocker::query()
            ->where('server_id', $server->id)
            ->where('network', $networkName)
            ->first();

        if ($standaloneDocker) {
            return $this->standaloneDefault(sourceId: $standaloneDocker->id);
        }

        $swarmDocker = SwarmDocker::query()
            ->where('server_id', $server->id)
            ->where('network', $networkName)
            ->first();

        if ($swarmDocker) {
            return $this->swarmDefault(sourceId: $swarmDocker->id);
        }

        $service = Service::query()
            ->where('server_id', $server->id)
            ->where('uuid', $networkName)
            ->first();

        if ($service) {
            return $this->resource(DockerNetworkSourceType::ServiceStackDefault->value, $service->id);
        }

        $application = $this->applicationOnServer($server, $networkName);

        if ($application) {
            return $this->resource(DockerNetworkSourceType::ComposeStackDefault->value, $application->id);
        }

        $preview = $this->previewOnServer($server, $networkName);

        if ($preview) {
            return [
                'source_type' => DockerNetworkSourceType::PreviewDeployment->value,
                'source_id' => $preview->id,
                'network_role' => DockerNetworkRole::PreviewStack->value,
                'managed_by_coolify' => false,
                'external' => true,
                'is_system' => false,
                'is_active' => true,
            ];
        }

        return [
            'source_type' => DockerNetworkSourceType::ImportedExternal->value,
            'source_id' => null,
            'network_role' => DockerNetworkRole::SharedExternal->value,
            'managed_by_coolify' => false,
            'external' => true,
            'is_system' => false,
            'is_active' => true,
        ];
    }

    private function system(): array
    {
        return [
            'source_type' => DockerNetworkSourceType::System->value,
            'source_id' => null,
            'network_role' => DockerNetworkRole::System->value,
            'managed_by_coolify' => false,
            'external' => true,
            'is_system' => true,
            'is_active' => true,
        ];
    }

    private function standaloneDefault(?int $sourceId = null, bool $isSystem = false): array
    {
        return [
            'source_type' => DockerNetworkSourceType::StandaloneDockerDestination->value,
            'source_id' => $sourceId,
            'network_role' => DockerNetworkRole::DefaultDestination->value,
            'managed_by_coolify' => false,
            'external' => true,
            'is_system' => $isSystem,
            'is_active' => true,
        ];
    }

    private function swarmDefault(?int $sourceId = null, bool $isSystem = false): array
    {
        return [
            'source_type' => DockerNetworkSourceType::SwarmDockerDestination->value,
            'source_id' => $sourceId,
            'network_role' => DockerNetworkRole::DefaultDestination->value,
            'managed_by_coolify' => false,
            'external' => true,
            'is_system' => $isSystem,
            'is_active' => true,
        ];
    }

    private function resource(string $sourceType, int $sourceId): array
    {
        return [
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'network_role' => DockerNetworkRole::ResourceStack->value,
            'managed_by_coolify' => false,
            'external' => true,
            'is_system' => false,
            'is_active' => true,
        ];
    }

    private function applicationOnServer(Server $server, string $networkName): ?Application
    {
        $standaloneDockerIds = $server->standaloneDockers()->pluck('id');
        $swarmDockerIds = $server->swarmDockers()->pluck('id');

        return Application::query()
            ->where('uuid', $networkName)
            ->where(function ($query) use ($standaloneDockerIds, $swarmDockerIds) {
                $query->where(function ($query) use ($standaloneDockerIds) {
                    $query->where('destination_type', StandaloneDocker::class)
                        ->whereIn('destination_id', $standaloneDockerIds);
                })->orWhere(function ($query) use ($swarmDockerIds) {
                    $query->where('destination_type', SwarmDocker::class)
                        ->whereIn('destination_id', $swarmDockerIds);
                });
            })
            ->first();
    }

    private function previewOnServer(Server $server, string $networkName): ?ApplicationPreview
    {
        $separatorPosition = strrpos($networkName, '-');

        if ($separatorPosition === false) {
            return null;
        }

        $applicationUuid = substr($networkName, 0, $separatorPosition);
        $pullRequestId = substr($networkName, $separatorPosition + 1);

        if ($applicationUuid === '' || ! ctype_digit($pullRequestId)) {
            return null;
        }

        $application = $this->applicationOnServer($server, $applicationUuid);

        if (! $application) {
            return null;
        }

        return ApplicationPreview::query()
            ->where('application_id', $application->id)
            ->where('pull_request_id', (int) $pullRequestId)
            ->first();
    }
}
