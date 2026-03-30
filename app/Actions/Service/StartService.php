<?php

namespace App\Actions\Service;

use App\Models\Service;
use App\Services\EdgeProxyRemotePortForwardService;
use App\Services\EdgeProxyRemoteRouteService;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Yaml\Yaml;

class StartService
{
    use AsAction;

    public string $jobQueue = 'high';

    public function handle(Service $service, bool $pullLatestImages = false, bool $stopBeforeStart = false)
    {
        $service->parse();
        $edgeRoutingWarnings = [];
        $edgePortForwardWarnings = [];
        try {
            $edgeRoutingWarnings = app(EdgeProxyRemoteRouteService::class)->syncService($service);
        } catch (\Throwable $exception) {
            Log::warning('Failed to sync edge proxy route for service start.', [
                'service_uuid' => $service->uuid,
                'error' => $exception->getMessage(),
            ]);
            $edgeRoutingWarnings[] = 'Failed to sync edge proxy route configuration. Check edge proxy connectivity and server settings.';
        }
        try {
            $edgePortForwardWarnings = app(EdgeProxyRemotePortForwardService::class)->syncService($service);
        } catch (\Throwable $exception) {
            Log::warning('Failed to sync edge port forwarding for service start.', [
                'service_uuid' => $service->uuid,
                'error' => $exception->getMessage(),
            ]);
            $edgePortForwardWarnings[] = 'Failed to sync edge port forwarding configuration. Check edge proxy connectivity, published ports, and server settings.';
        }
        if ($stopBeforeStart) {
            StopService::run(service: $service, dockerCleanup: false);
        }
        $service->saveComposeConfigs();
        $service->isConfigurationChanged(save: true);
        $workdir = $service->workdir();
        // $commands[] = "cd {$workdir}";
        $commands[] = "echo 'Saved configuration files to {$workdir}.'";
        foreach ($edgeRoutingWarnings as $warning) {
            $commands[] = 'echo '.escapeshellarg("Edge proxy routing warning: {$warning}");
        }
        foreach ($edgePortForwardWarnings as $warning) {
            $commands[] = 'echo '.escapeshellarg("Edge port forwarding warning: {$warning}");
        }
        // Ensure .env exists in the correct directory before docker compose tries to load it
        // This is defensive programming - saveComposeConfigs() already creates it,
        // but we guarantee it here in case of any edge cases or manual deployments
        $commands[] = "touch {$workdir}/.env";
        if ($pullLatestImages) {
            $commands[] = "echo 'Pulling images.'";
            $commands[] = "docker compose --project-directory {$workdir} pull";
        }
        if ($service->networks()->count() > 0) {
            $commands[] = "echo 'Creating Docker network.'";
            $commands[] = "docker network inspect $service->uuid >/dev/null 2>&1 || docker network create --attachable $service->uuid";
        }
        $commands[] = 'echo Starting service.';
        $commands[] = "docker compose --project-directory {$workdir} -f {$workdir}/docker-compose.yml --project-name {$service->uuid} up -d --remove-orphans --force-recreate --build";
        $commands[] = "docker network connect $service->uuid coolify-proxy >/dev/null 2>&1 || true";
        if (data_get($service, 'connect_to_docker_network')) {
            $compose = data_get($service, 'docker_compose', []);
            $safeNetwork = escapeshellarg($service->destination->network);
            $serviceNames = data_get(Yaml::parse($compose), 'services', []);
            foreach ($serviceNames as $serviceName => $serviceConfig) {
                $commands[] = "docker network connect --alias {$serviceName}-{$service->uuid} {$safeNetwork} {$serviceName}-{$service->uuid} >/dev/null 2>&1 || true";
            }
        }

        return remote_process($commands, $service->server, type_uuid: $service->uuid, callEventOnFinish: 'ServiceStatusChanged');
    }
}
