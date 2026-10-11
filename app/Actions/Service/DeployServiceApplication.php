<?php

namespace App\Actions\Service;

use App\Actions\Shared\EnsureContentFilesOnServer;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use Lorisleiva\Actions\Concerns\AsAction;
use Spatie\Activitylog\Contracts\Activity;

class DeployServiceApplication
{
    use AsAction;

    public string $jobQueue = 'high';

    public function handle(ServiceApplication|ServiceDatabase $serviceApplication, bool $pullLatestImages = false, bool $forceRebuild = false): Activity
    {
        $service = $serviceApplication->service;
        $composeServiceName = $serviceApplication->name;

        $service->parse();
        $service->saveComposeConfigs();
        $service->isConfigurationChanged(save: true);

        $workdir = $service->workdir();
        $composeFile = "{$workdir}/docker-compose.yml";
        $safeWorkdir = escapeshellarg($workdir);
        $safeComposeFile = escapeshellarg($composeFile);
        $safeProjectName = escapeshellarg($service->uuid);
        $safeComposeServiceName = escapeshellarg($composeServiceName);

        $commands = collect([
            'echo '.escapeshellarg("Saved configuration files to {$workdir}."),
            'touch '.escapeshellarg("{$workdir}/.env"),
            ...EnsureContentFilesOnServer::echoCommands($serviceApplication->fileStorages()->get(), $service->server),
            ...StartService::composeVolumeWarningCommands($service),
        ]);

        if ($pullLatestImages) {
            $commands->push('echo Pulling image for service.');
            $commands->push("docker compose --project-directory {$safeWorkdir} -f {$safeComposeFile} --project-name {$safeProjectName} pull {$safeComposeServiceName} < /dev/null");
        }

        if ($service->networks()->count() > 0) {
            $commands->push('echo Creating Docker network.');
            $commands->push("docker network inspect {$safeProjectName} >/dev/null 2>&1 || docker network create --attachable {$safeProjectName}");
        }

        $upCommand = "docker compose --project-directory {$safeWorkdir} -f {$safeComposeFile} --project-name {$safeProjectName} up -d --no-deps";
        if ($forceRebuild) {
            $upCommand .= ' --build';
        }
        // No stdin for Compose prompts: they would read the next script lines (see StartService).
        $upCommand .= " {$safeComposeServiceName} < /dev/null";
        $commands->push('echo Starting service container.');
        $commands->push($upCommand);

        $commands->push("docker network connect {$safeProjectName} coolify-proxy >/dev/null 2>&1 || true");

        if (data_get($service, 'connect_to_docker_network')) {
            $network = escapeshellarg($service->destination->network);
            $containerName = escapeshellarg("{$composeServiceName}-{$service->uuid}");
            $networkAlias = escapeshellarg("{$composeServiceName}-{$service->uuid}");
            $commands->push("docker network connect --alias {$networkAlias} {$network} {$containerName} >/dev/null 2>&1 || true");
        }

        return remote_process($commands->toArray(), $service->server, type_uuid: $service->uuid, callEventOnFinish: 'ServiceStartFinished', callEventData: $service->id, queue: deployment_queue());
    }
}
