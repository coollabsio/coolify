<?php

namespace App\Actions\Service;

use App\Actions\Shared\EnsureContentFilesOnServer;
use App\Models\LocalFileVolume;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;
use Lorisleiva\Actions\Decorators\JobDecorator;

class StartService
{
    use AsAction;

    public function configureJob(JobDecorator $job): void
    {
        $job->onQueue(deployment_queue());
    }

    public function handle(Service $service, bool $pullLatestImages = false, bool $stopBeforeStart = false)
    {
        $service->parse();
        // Fetch remote secrets before stopping: if the secret manager fails, the service keeps running.
        // saveComposeConfigs() below reuses the fetched secrets.
        $service->ensureRemoteSecretsResolvable($service->environment_variables()->get());
        if ($this->shouldStopBeforeStarting($pullLatestImages, $stopBeforeStart)) {
            StopService::run(service: $service, dockerCleanup: false);
        }
        $service->saveComposeConfigs();
        $service->isConfigurationChanged(save: true);
        $service->applications()->get()->each->resetRestartLimit();
        $workdir = $service->workdir();
        // $commands[] = "cd {$workdir}";
        $commands[] = "echo 'Saved configuration files to {$workdir}.'";
        // Ensure .env exists in the correct directory before docker compose tries to load it
        // This is defensive programming - saveComposeConfigs() already creates it,
        // but we guarantee it here in case of any edge cases or manual deployments
        $commands[] = "touch {$workdir}/.env";
        $commands = array_merge($commands, EnsureContentFilesOnServer::echoCommands($this->contentFileStorages($service), $service->server));
        $commands = array_merge($commands, self::composeVolumeWarningCommands($service));
        // The script reaches the server on stdin. Compose prompts (for example "Volume ... exists but
        // doesn't match configuration. Recreate?") would read the next script lines as the answer.
        // Give Compose no stdin so it uses the default answer (keep the volume). Never pass --yes:
        // it recreates the volume and deletes its data.
        // Pass -f so Compose uses only the file that Coolify wrote.
        $compose = "docker compose --project-directory {$workdir} -f {$workdir}/docker-compose.yml --project-name {$service->uuid}";
        if ($pullLatestImages) {
            $commands[] = "echo 'Pulling images.'";
            $commands[] = "{$compose} pull < /dev/null";
        }
        if ($service->networks()->count() > 0) {
            $commands[] = "echo 'Creating Docker network.'";
            $commands[] = "docker network inspect $service->uuid >/dev/null 2>&1 || docker network create --attachable $service->uuid";
        }
        $commands[] = 'echo Starting service.';
        if (data_get($service, 'connect_to_docker_network')) {
            // Connect the created containers to the predefined network before they start. A container
            // that needs this network during a service_healthy wait never gets it after `up -d`.
            $commands[] = "{$compose} up -d --remove-orphans --force-recreate --build --no-start < /dev/null";
            $composeServices = data_get(parseDockerComposeYaml(data_get($service, 'docker_compose', '')), 'services', []);
            foreach ($composeServices as $serviceName => $serviceConfig) {
                if ($command = self::predefinedNetworkConnectCommand($service, $serviceName, $serviceConfig)) {
                    $commands[] = $command;
                }
            }
            $commands[] = "{$compose} start < /dev/null";
        } else {
            $commands[] = "{$compose} up -d --remove-orphans --force-recreate --build < /dev/null";
        }
        $commands[] = "docker network connect $service->uuid coolify-proxy >/dev/null 2>&1 || true";
        $commands = array_merge($commands, $this->logDrainNetworkConnectCommands($service));
        if ($service->is_maintenance_enabled) {
            // Domains can change with the compose file, so the maintenance routes follow them.
            try {
                $service->syncMaintenancePage();
            } catch (\Throwable $e) {
                Log::warning('Maintenance page update failed while starting service.', ['service_uuid' => $service->uuid, 'error' => $e->getMessage()]);
            }
        }

        return remote_process($commands, $service->server, type_uuid: $service->uuid, callEventOnFinish: 'ServiceStartFinished', callEventData: $service->id, queue: deployment_queue());
    }

    /**
     * Connects a container to the predefined network of the destination, unless it is already
     * connected (declared in the compose file, or a running container that was not recreated).
     * Containers with a network_mode cannot join other networks and are skipped.
     */
    public static function predefinedNetworkConnectCommand(Service $service, string $serviceName, mixed $serviceConfig): ?string
    {
        if (data_get($serviceConfig, 'network_mode') !== null) {
            return null;
        }
        $network = escapeshellarg($service->destination->network);
        $containerName = escapeshellarg("{$serviceName}-{$service->uuid}");
        $warning = escapeshellarg("Warning: Could not connect {$serviceName} to the {$service->destination->network} network.");
        $connectedNetworks = "docker container inspect --format '{{range \$name, \$_ := .NetworkSettings.Networks}}{{println \$name}}{{end}}' {$containerName}";

        return "{$connectedNetworks} | grep -qxF {$network} || docker network connect --alias {$containerName} {$network} {$containerName} || echo {$warning}";
    }

    /**
     * Shows the volume warnings of the last parse (for example an external volume that the
     * service does not use yet) in the start log.
     *
     * @return list<string>
     */
    public static function composeVolumeWarningCommands(Service $service): array
    {
        return array_map(
            fn (string $warning): string => 'echo '.escapeshellarg("Warning: {$warning}"),
            $service->composeVolumeWarnings()
        );
    }

    /**
     * @return Collection<int, LocalFileVolume>
     */
    private function contentFileStorages(Service $service): Collection
    {
        return LocalFileVolume::query()
            ->where(function ($query) use ($service) {
                $query->where('resource_type', (new ServiceApplication)->getMorphClass())
                    ->whereIn('resource_id', $service->applications()->select('id'));
            })
            ->orWhere(function ($query) use ($service) {
                $query->where('resource_type', (new ServiceDatabase)->getMorphClass())
                    ->whereIn('resource_id', $service->databases()->select('id'));
            })
            ->get();
    }

    private function logDrainNetworkConnectCommands(Service $service): array
    {
        if (! data_get($service, 'connect_to_docker_network')) {
            return [];
        }

        if (! $service->destination?->server?->isFluentBitLogDrainEnabled()) {
            return [];
        }

        $network = data_get($service, 'destination.network');

        if (blank($network)) {
            return [];
        }

        return [
            'docker network connect '.escapeshellarg($network).' coolify-log-drain >/dev/null 2>&1 || true',
        ];
    }

    private function shouldStopBeforeStarting(bool $pullLatestImages, bool $stopBeforeStart): bool
    {
        return $stopBeforeStart && ! $pullLatestImages;
    }
}
