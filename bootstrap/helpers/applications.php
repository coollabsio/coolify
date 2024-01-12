<?php

use App\Jobs\ApplicationDeployDockerImageJob;
use App\Jobs\ApplicationDeploymentJob;
use App\Jobs\ApplicationDeploymentNewJob;
use App\Jobs\ApplicationDeploySimpleDockerfileJob;
use App\Jobs\ApplicationRestartJob;
use App\Jobs\MultipleApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\Server;
use Symfony\Component\Yaml\Yaml;

function queue_application_deployment(int $application_id, string $deployment_uuid, int | null $pull_request_id = 0, string $commit = 'HEAD', bool $force_rebuild = false, bool $is_webhook = false, bool $restart_only = false, ?string $git_type = null, bool $is_new_deployment = false)
{
    $deployment = ApplicationDeploymentQueue::create([
        'application_id' => $application_id,
        'deployment_uuid' => $deployment_uuid,
        'pull_request_id' => $pull_request_id,
        'force_rebuild' => $force_rebuild,
        'is_webhook' => $is_webhook,
        'restart_only' => $restart_only,
        'commit' => $commit,
        'git_type' => $git_type
    ]);
    $queued_deployments = ApplicationDeploymentQueue::where('application_id', $application_id)->where('status', 'queued')->get()->sortByDesc('created_at');
    $running_deployments = ApplicationDeploymentQueue::where('application_id', $application_id)->where('status', 'in_progress')->get()->sortByDesc('created_at');
    ray('Q:' . $queued_deployments->count() . 'R:' . $running_deployments->count() . '| Queuing deployment: ' . $deployment_uuid . ' of applicationID: ' . $application_id . ' pull request: ' . $pull_request_id . ' with commit: ' . $commit . ' and is it forced: ' . $force_rebuild);
    if ($queued_deployments->count() > 1) {
        $queued_deployments = $queued_deployments->skip(1);
        $queued_deployments->each(function ($queued_deployment, $key) {
            $queued_deployment->status = 'cancelled by system';
            $queued_deployment->save();
        });
    }
    if ($running_deployments->count() > 0) {
        return;
    }
    if ($is_new_deployment) {
        dispatch(new ApplicationDeploymentNewJob(
            deployment: $deployment,
            application: Application::find($application_id)
        ));
    } else {
        dispatch(new ApplicationDeploymentJob(
            application_deployment_queue_id: $deployment->id,
        ));
    }
}

function queue_next_deployment(Application $application, bool $isNew = false)
{
    $next_found = ApplicationDeploymentQueue::where('application_id', $application->id)->where('status', 'queued')->first();
    if ($next_found) {
        if ($isNew) {
            dispatch(new ApplicationDeploymentNewJob(
                deployment: $next_found,
                application: $application
            ));
        } else {
            dispatch(new ApplicationDeploymentJob(
                application_deployment_queue_id: $next_found->id,
            ));
        }
    }
}
// Deployment things
function generateHostIpMapping(Server $server, string $network)
{
    // Generate custom host<->ip hostnames
    $allContainers = instant_remote_process(["docker network inspect {$network} -f '{{json .Containers}}' "], $server);
    $allContainers = format_docker_command_output_to_json($allContainers);
    $ips = collect([]);
    if (count($allContainers) > 0) {
        $allContainers = $allContainers[0];
        foreach ($allContainers as $container) {
            $containerName = data_get($container, 'Name');
            if ($containerName === 'coolify-proxy') {
                continue;
            }
            $containerIp = data_get($container, 'IPv4Address');
            if ($containerName && $containerIp) {
                $containerIp = str($containerIp)->before('/');
                $ips->put($containerName, $containerIp->value());
            }
        }
    }
    return $ips->map(function ($ip, $name) {
        return "--add-host $name:$ip";
    })->implode(' ');
}

function generateBaseDir(string $deplyomentUuid)
{
    return "/artifacts/$deplyomentUuid";
}
function generateWorkdir(string $deplyomentUuid, Application $application)
{
    return generateBaseDir($deplyomentUuid) . rtrim($application->base_directory, '/');
}

function prepareHelperContainer(Server $server, string $network, string $deploymentUuid)
{
    $basedir = generateBaseDir($deploymentUuid);
    $helperImage = config('coolify.helper_image');

    $serverUserHomeDir = instant_remote_process(["echo \$HOME"], $server);
    $dockerConfigFileExists = instant_remote_process(["test -f {$serverUserHomeDir}/.docker/config.json && echo 'OK' || echo 'NOK'"], $server);

    $commands = collect([]);
    if ($dockerConfigFileExists === 'OK') {
        $commands->push([
            "command" => "docker run -d --network $network --name $deploymentUuid --rm -v {$serverUserHomeDir}/.docker/config.json:/root/.docker/config.json:ro -v /var/run/docker.sock:/var/run/docker.sock $helperImage",
            "hidden" => true,
        ]);
    } else {
        $commands->push([
            "command" => "docker run -d --network {$network} --name {$deploymentUuid} --rm -v /var/run/docker.sock:/var/run/docker.sock {$helperImage}",
            "hidden" => true,
        ]);
    }
    $commands->push([
        "command" => executeInDocker($deploymentUuid, "mkdir -p {$basedir}"),
        "hidden" => true,
    ]);
    return $commands;
}

function generateComposeFile(string $deploymentUuid, Server $server, string $network, Application $application, string $containerName, string $imageName, ?ApplicationPreview $preview = null, int $pullRequestId = 0)
{
    $ports = $application->settings->is_static ? [80] : $application->ports_exposes_array;
    $workDir = generateWorkdir($deploymentUuid, $application);
    $persistent_storages = generateLocalPersistentVolumes($application, $pullRequestId);
    $volume_names = generateLocalPersistentVolumesOnlyVolumeNames($application, $pullRequestId);
    $environment_variables = generateEnvironmentVariables($application, $ports, $pullRequestId);

    if (data_get($application, 'custom_labels')) {
        $labels = collect(str($application->custom_labels)->explode(','));
        $labels = $labels->filter(function ($value, $key) {
            return !str($value)->startsWith('coolify.');
        });
        $application->custom_labels = $labels->implode(',');
        $application->save();
    } else {
        $labels = collect(generateLabelsApplication($application, $preview));
    }
    if ($pullRequestId !== 0) {
        $labels = collect(generateLabelsApplication($application, $preview));
    }
    $labels = $labels->merge(defaultLabels($application->id, $application->uuid, 0))->toArray();
    $docker_compose = [
        'version' => '3.8',
        'services' => [
            $containerName => [
                'image' => $imageName,
                'container_name' => $containerName,
                'restart' => RESTART_MODE,
                'environment' => $environment_variables,
                'labels' => $labels,
                'expose' => $ports,
                'networks' => [
                    $network,
                ],
                'mem_limit' => $application->limits_memory,
                'memswap_limit' => $application->limits_memory_swap,
                'mem_swappiness' => $application->limits_memory_swappiness,
                'mem_reservation' => $application->limits_memory_reservation,
                'cpus' => (int) $application->limits_cpus,
                'cpu_shares' => $application->limits_cpu_shares,
            ]
        ],
        'networks' => [
            $network => [
                'external' => true,
                'name' => $network,
                'attachable' => true
            ]
        ]
    ];
    if ($application->limits_cpuset !== 0) {
        data_set($docker_compose, "services.{$containerName}.cpuset", $application->limits_cpuset);
    }
    if ($server->isLogDrainEnabled() && $application->isLogDrainEnabled()) {
        $docker_compose['services'][$containerName]['logging'] = [
            'driver' => 'fluentd',
            'options' => [
                'fluentd-address' => "tcp://127.0.0.1:24224",
                'fluentd-async' => "true",
                'fluentd-sub-second-precision' => "true",
            ]
        ];
    }
    if ($application->settings->is_gpu_enabled) {
        $docker_compose['services'][$containerName]['deploy']['resources']['reservations']['devices'] = [
            [
                'driver' => data_get($application, 'settings.gpu_driver', 'nvidia'),
                'capabilities' => ['gpu'],
                'options' => data_get($application, 'settings.gpu_options', [])
            ]
        ];
        if (data_get($application, 'settings.gpu_count')) {
            $count = data_get($application, 'settings.gpu_count');
            if ($count === 'all') {
                $docker_compose['services'][$containerName]['deploy']['resources']['reservations']['devices'][0]['count'] = $count;
            } else {
                $docker_compose['services'][$containerName]['deploy']['resources']['reservations']['devices'][0]['count'] = (int) $count;
            }
        } else if (data_get($application, 'settings.gpu_device_ids')) {
            $docker_compose['services'][$containerName]['deploy']['resources']['reservations']['devices'][0]['ids'] = data_get($application, 'settings.gpu_device_ids');
        }
    }
    if ($application->isHealthcheckDisabled()) {
        data_forget($docker_compose, 'services.' . $containerName . '.healthcheck');
    }
    if (count($application->ports_mappings_array) > 0 && $pullRequestId === 0) {
        $docker_compose['services'][$containerName]['ports'] = $application->ports_mappings_array;
    }
    if (count($persistent_storages) > 0) {
        $docker_compose['services'][$containerName]['volumes'] = $persistent_storages;
    }
    if (count($volume_names) > 0) {
        $docker_compose['volumes'] = $volume_names;
    }
    $docker_compose = Yaml::dump($docker_compose, 10);
    $docker_compose_base64 = base64_encode($docker_compose);
    $commands = collect([]);
    $commands->push([
        "command" => executeInDocker($deploymentUuid, "echo '{$docker_compose_base64}' | base64 -d > {$workDir}/docker-compose.yml"),
        "hidden" => true,
    ]);
    return $commands;
}
function generateLocalPersistentVolumes(Application $application, int $pullRequestId = 0)
{
    $local_persistent_volumes = [];
    foreach ($application->persistentStorages as $persistentStorage) {
        $volume_name = $persistentStorage->host_path ?? $persistentStorage->name;
        if ($pullRequestId !== 0) {
            $volume_name = $volume_name . '-pr-' . $pullRequestId;
        }
        $local_persistent_volumes[] = $volume_name . ':' . $persistentStorage->mount_path;
    }
    return $local_persistent_volumes;
}

function generateLocalPersistentVolumesOnlyVolumeNames(Application $application, int $pullRequestId = 0)
{
    $local_persistent_volumes_names = [];
    foreach ($application->persistentStorages as $persistentStorage) {
        if ($persistentStorage->host_path) {
            continue;
        }
        $name = $persistentStorage->name;

        if ($pullRequestId !== 0) {
            $name = $name . '-pr-' . $pullRequestId;
        }

        $local_persistent_volumes_names[$name] = [
            'name' => $name,
            'external' => false,
        ];
    }
    return $local_persistent_volumes_names;
}
function generateEnvironmentVariables(Application $application, $ports, int $pullRequestId = 0)
{
    $environment_variables = collect();
    // ray('Generate Environment Variables')->green();
    if ($pullRequestId === 0) {
        // ray($this->application->runtime_environment_variables)->green();
        foreach ($application->runtime_environment_variables as $env) {
            $environment_variables->push("$env->key=$env->value");
        }
        foreach ($application->nixpacks_environment_variables as $env) {
            $environment_variables->push("$env->key=$env->value");
        }
    } else {
        // ray($this->application->runtime_environment_variables_preview)->green();
        foreach ($application->runtime_environment_variables_preview as $env) {
            $environment_variables->push("$env->key=$env->value");
        }
        foreach ($application->nixpacks_environment_variables_preview as $env) {
            $environment_variables->push("$env->key=$env->value");
        }
    }
    // Add PORT if not exists, use the first port as default
    if ($environment_variables->filter(fn ($env) => str($env)->contains('PORT'))->isEmpty()) {
        $environment_variables->push("PORT={$ports[0]}");
    }
    return $environment_variables->all();
}

function startNewApplication(Application $application, string $deploymentUuid, ApplicationDeploymentQueue $loggingModel)
{
    $commands = collect([]);
    $workDir = generateWorkdir($deploymentUuid, $application);
    if ($application->build_pack === 'dockerimage') {
        $loggingModel->addLogEntry('Pulling latest images from the registry.');
        $commands->push(
            [
                "command" => executeInDocker($deploymentUuid, "docker compose --project-directory {$workDir} pull"),
                "hidden" => true
            ],
            [
                "command" => executeInDocker($deploymentUuid, "docker compose --project-directory {$workDir} up --build -d"),
                "hidden" => true
            ],
        );
    } else {
        $commands->push(
            [
                "command" => executeInDocker($deploymentUuid, "docker compose --project-directory {$workDir} up --build -d"),
                "hidden" => true
            ],
        );
    }
    return $commands;
}
function removeOldDeployment(string $containerName)
{
    $commands = collect([]);
    $commands->push(
        ["docker rm -f $containerName >/dev/null 2>&1"],
    );
    return $commands;
}
