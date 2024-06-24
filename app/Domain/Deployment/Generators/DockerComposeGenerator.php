<?php

namespace App\Domain\Deployment\Generators;

use App\Domain\Deployment\DeploymentAction\Abstract\DeploymentBaseAction;
use App\Domain\Deployment\DeploymentConfig;
use App\Domain\Remote\Commands\RemoteCommand;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

class DockerComposeGenerator
{
    private DeploymentBaseAction $deploymentAction;

    public function __construct(DeploymentBaseAction $deploymentAction)
    {

        $this->deploymentAction = $deploymentAction;
    }

    private const DOCKERFILE_FROM_REPO = 'dockerfile_from_repo';

    public function generate(): void
    {
        $application = $this->deploymentAction->getApplication();
        $config = $this->deploymentAction->getContext()->getDeploymentConfig();
        $preview = $config->getPreview();

        $workDir = $config->getWorkDir();
        $ports = $application->main_port();

        $onlyPort = count($ports) > 0 ? $ports[0] : null;

        $persistentStorages = $this->generateLocalPersistentVolumes();
        $persistentFileVolumes = $application->fileStorages()->get();
        $volumeNames = $this->generateLocalPersistentVolumesOnlyVolumeNames();

        $this->writeEnvironmentVariables();

        $applicationDeploymentQueue = $this->deploymentAction->getContext()->getApplicationDeploymentQueue();

        $pullRequestId = $applicationDeploymentQueue->pull_request_id;

        $labels = $this->generateLabels($application, $applicationDeploymentQueue, $preview, $onlyPort, $pullRequestId);

        $savedLogs = $this->deploymentAction->getContext()->getDeploymentResult()->savedLogs;

        if ($application->build_pack === 'dockerfile' || $application->dockerfile) {
            $this->deploymentAction->getContext()->getDeploymentHelper()
                ->executeAndSave([
                    new RemoteCommand(executeInDocker($applicationDeploymentQueue->deployment_uuid, "cat {$config->getWorkDir()}{$application->dockerfile_location}"), hidden: true, save: self::DOCKERFILE_FROM_REPO, ignoreErrors: true),
                ], $this->deploymentAction->getContext()->getApplicationDeploymentQueue(), $savedLogs);

            $dockerFile = collect(str($savedLogs->get(self::DOCKERFILE_FROM_REPO))
                ->trim()
                ->explode("\n")
            );

            $application->parseHealthcheckFromDockerfile($dockerFile);
        }

        // generate docker compose
        $containerName = $config->getContainerName();
        $dockerImageNames = $this->deploymentAction->generateDockerImageNames();
        $destination = $config->getDestination();
        $dockerCompose = $this->generateDockerCompose($containerName, $dockerImageNames['productionImageName'], $ports, $destination, $application, $config, $labels, $pullRequestId, $persistentStorages, $persistentFileVolumes, $volumeNames);

        $dockerComposeGenerated = Yaml::dump($dockerCompose, 10);
        $dockerComposeBase64 = base64_encode($dockerComposeGenerated);

        $result = $this->deploymentAction->getContext()->getDeploymentResult();
        $result->setDockerCompose($dockerComposeGenerated);
        $result->setDockerComposeBase64($dockerComposeBase64);

        $this->deploymentAction->getContext()
            ->getDeploymentHelper()
            ->executeAndSave([
                new RemoteCommand(
                    executeInDocker($applicationDeploymentQueue->deployment_uuid, "echo '{$dockerComposeBase64}' | base64 -d | tee {$config->getWorkDir()}/docker-compose.yml > /dev/null"),
                    hidden: true
                ),
            ], $applicationDeploymentQueue, $savedLogs);
    }

    private function generateEnvironmentVariables(): Collection
    {
        // @see save_environment_variables
        $envs = collect();

        $commands = $this->deploymentAction->getContext()->generateGitImportCommands();
        $applicationDeploymentQueue = $this->deploymentAction->getContext()->getApplicationDeploymentQueue();
        $application = $this->deploymentAction->getApplication();

        $branch = $commands['branch'];
        $commit = $applicationDeploymentQueue->commit;

        $pullRequestId = $applicationDeploymentQueue->pull_request_id;
        if ($pullRequestId !== 0) {
            $branch = "pull/{$pullRequestId}/head";
        }

        $envSortingEnabled = $application->settings->is_env_sorting_enabled;

        $sortByKey = $envSortingEnabled ? 'key' : 'id';

        $sortedEnvVariables = $application->environment_variables->sortBy($sortByKey);
        $sortedEnvVariablesPreview = $application->environment_variables_preview->sortBy($sortByKey);

        $ports = $application->main_port();

        $environmentVariablesToUse = $pullRequestId !== 0 ? $sortedEnvVariablesPreview : $sortedEnvVariables;

        if ($environmentVariablesToUse->where('key', 'SOURCE_COMMIT')->isEmpty()) {
            $commitToSet = is_null($commit) ? 'unknown' : $commit;
            $envs->push("SOURCE_COMMIT={$commitToSet}");
        }

        if ($environmentVariablesToUse->where('key', 'COOLIFY_FQDN')->isEmpty()) {
            $fqdn = $pullRequestId !== 0 ? $this->deploymentAction->getContext()->getDeploymentConfig()->getPreview()->fqdn
                : $application->fqdn;
            $envs->push("COOLIFY_FQDN={$fqdn}");
        }

        if ($environmentVariablesToUse->where('key', 'COOLIFY_BRANCH')->isEmpty()) {
            $envs->push("COOLIFY_BRANCH={$branch}");
        }

        if ($environmentVariablesToUse->where('key', 'COOLIFY_CONTAINER_NAME')->isEmpty()) {
            $envs->push("COOLIFY_CONTAINER_NAME={$this->deploymentAction->getContext()->getDeploymentConfig()->getContainerName()}");
        }

        if ($environmentVariablesToUse->where('key', 'PORT')->isEmpty()) {
            $envs->push("PORT={$ports[0]}");
        }

        if ($environmentVariablesToUse->where('key', 'HOST')->isEmpty()) {
            $envs->push('HOST=0.0.0.0');
        }

        foreach ($environmentVariablesToUse as $env) {
            $realValue = $env->real_value;

            if ($env->is_literal || $env->is_multiline) {
                $realValue = '\''.$realValue.'\'';
            } else {
                $realValue = escapeEnvVariables($realValue);
            }

            $envs->push("{$env->key}={$realValue}");
        }

        return $envs;
    }

    private function generateLocalPersistentVolumes(): array
    {
        $localPersistentVolumes = [];

        $application = $this->deploymentAction->getApplication();
        $applicationQueue = $this->deploymentAction->getContext()->getApplicationDeploymentQueue();

        foreach ($application->persistentStorages as $persistentStorage) {
            if ($persistentStorage->host_path !== '' && $persistentStorage->host_path !== null) {
                $volume_name = $persistentStorage->host_path;
            } else {
                $volume_name = $persistentStorage->name;
            }
            if ($applicationQueue->pull_request_id !== 0) {
                $volume_name = $volume_name.'-pr-'.$applicationQueue->pull_request_id;
            }
            $localPersistentVolumes[] = $volume_name.':'.$persistentStorage->mount_path;
        }

        return $localPersistentVolumes;
    }

    private function generateLocalPersistentVolumesOnlyVolumeNames(): array
    {
        $localPersistentVolumeNames = [];
        $applicationQueue = $this->deploymentAction->getContext()->getApplicationDeploymentQueue();

        foreach ($this->deploymentAction->getApplication()->persistentStorages as $persistentStorage) {
            if ($persistentStorage->host_path) {
                continue;
            }
            $name = $persistentStorage->name;

            if ($applicationQueue->pull_request_id !== 0) {
                $name = $name.'-pr-'.$applicationQueue->pull_request_id;
            }

            $localPersistentVolumeNames[$name] = [
                'name' => $name,
                'external' => false,
            ];
        }

        return $localPersistentVolumeNames;
    }

    /**
     * @throws \App\Exceptions\DeploymentCommandFailedException
     */
    private function saveEnvironmentVariablesToServer(ApplicationDeploymentQueue $applicationDeploymentQueue, string $base64envs, string $workDir, DeploymentConfig $config): void
    {

        $envFilename = $this->deploymentAction->getContext()->getDeploymentConfig()->getEnvFileName();

        $this->deploymentAction->getContext()->switchToOriginalServer();

        $this->deploymentAction->getContext()->getDeploymentHelper()
            ->executeAndSave([
                new RemoteCommand(executeInDocker($applicationDeploymentQueue->deployment_uuid, "echo '$base64envs' | base64 -d | tee {$workDir}/{$envFilename} > /dev/null")),
            ], $this->deploymentAction->getContext()->getApplicationDeploymentQueue(), $this->deploymentAction->getContext()->getDeploymentResult()->savedLogs);

        if ($config->useBuildServer()) {
            $this->deploymentAction->getContext()->switchToBuildServer();
        }

    }

    public function generateCustomLabels(Application $application, ApplicationDeploymentQueue $applicationDeploymentQueue, ?ApplicationPreview $preview, mixed $onlyPort): Collection
    {
        $application->parseContainerLabels();

        $labels = collect(preg_split("/\r\n|\n|\r/", base64_decode($application->custom_labels)));

        $labels = $labels->filter(function ($value, $key) {
            return ! Str::startsWith($value, 'coolify.');
        });

        $foundCaddyLabels = $labels->filter(function ($value, $key) {
            return Str::startsWith($value, 'caddy_');
        });

        $pullRequestId = $applicationDeploymentQueue->pull_request_id;

        if ($foundCaddyLabels->isEmpty()) {
            $fqdn = $pullRequestId !== 0 ? $preview->fqdn : $application->fqdn;

            $domains = str($fqdn)->explode(',');

            $labels = $labels->merge(fqdnLabelsForCaddy(
                network: $application->destination->network,
                uuid: $application->uuid,
                domains: $domains,
                is_force_https_enabled: $application->isForceHttpsEnabled(),
                onlyPort: $onlyPort,
                is_gzip_enabled: $application->isGzipEnabled(),
                is_stripprefix_enabled: $application->isStripprefixEnabled()
            ));
        }

        $application->custom_labels = base64_encode($labels->implode("\n"));
        $application->save();

        return $labels;
    }

    /**
     * @return void
     */
    private function generateLabels(Application $application, ApplicationDeploymentQueue $applicationDeploymentQueue, ?ApplicationPreview $preview, mixed $onlyPort, int $pullRequestId): array
    {
        if ($application->custom_labels) {
            $labels = $this->generateCustomLabels($application, $applicationDeploymentQueue, $preview, $onlyPort);
        } else {
            $labels = collect(generateLabelsApplication($application, $preview));
        }

        if ($pullRequestId !== 0) {
            $labels = collect(generateLabelsApplication($application, $preview));
        }

        if ($application->settings->is_container_label_escape_enabled) {
            $labels = $labels->map(function ($value, $key) {
                return escapeDollarSign($value);
            });
        }

        $labels = $labels->merge(defaultLabels($application->id, $application->uuid, $pullRequestId))->toArray();

        return $labels;
    }

    private function generateHealthCheckCommand(Application $application): string
    {
        $healthCheckPort = $application->health_check_port ?: $application->ports_exposes_array[0];

        if ($application->settings->is_static || $application->build_pack === 'static') {
            $healthCheckPort = 80;
        }

        if ($application->health_check_path) {
            $fullHealthcheckUrl = "{$application->health_check_method}: {$application->health_check_scheme}://{$application->health_check_host}:{$healthCheckPort}{$application->health_check_path}";

            $generatedHealthChecksCommand = [
                "curl -s -X {$application->health_check_method} -f {$application->health_check_scheme}://{$application->health_check_host}:{$healthCheckPort}{$application->health_check_path} > /dev/null || wget -q -O- {$application->health_check_scheme}://{$application->health_check_host}:{$healthCheckPort}{$application->health_check_path} > /dev/null || exit 1",
            ];
        } else {
            $fullHealthcheckUrl = "{$application->health_check_method}: {$application->health_check_scheme}://{$application->health_check_host}:{$healthCheckPort}/";
            $generatedHealthChecksCommand = [
                "curl -s -X {$application->health_check_method} -f {$application->health_check_scheme}://{$application->health_check_host}:{$healthCheckPort}/ > /dev/null || wget -q -O- {$application->health_check_scheme}://{$application->health_check_host}:{$healthCheckPort}/ > /dev/null || exit 1",
            ];
        }

        $this->deploymentAction->getContext()->getDeploymentResult()
            ->setFullHealthCheckUrl($fullHealthcheckUrl);

        return implode(' ', $generatedHealthChecksCommand);
    }

    private function generateDockerCompose(string $containerName, $productionImageName, mixed $ports, StandaloneDocker|SwarmDocker $destination, Application $application, DeploymentConfig $config, ?array $labels, int $pullRequestId, array $persistentStorages, \Illuminate\Database\Eloquent\Collection $persistentFileVolumes, array $volumeNames): array
    {
        $dockerCompose = [
            'services' => [
                $containerName => [
                    'image' => $productionImageName,
                    'container_name' => $containerName,
                    'restart' => RESTART_MODE,
                    'expose' => $ports,
                    'networks' => [
                        $destination->network => [
                            'aliases' => [
                                $containerName,
                            ],
                        ],
                    ],
                    'healthcheck' => [
                        'test' => [
                            'CMD-SHELL',
                            $this->generateHealthCheckCommand($application),
                        ],
                        'interval' => $application->health_check_interval.'s',
                        'timeout' => $application->health_check_timeout.'s',
                        'retries' => $application->health_check_retries,
                        'start_period' => $application->health_check_start_period.'s',
                    ],
                    'mem_limit' => $application->limits_memory,
                    'memswap_limit' => $application->limits_memory_swap,
                    'mem_swappiness' => $application->limits_memory_swappiness,
                    'mem_reservation' => $application->limits_memory_reservation,
                    'cpus' => (float) $application->limits_cpus,
                    'cpu_shares' => $application->limits_cpu_shares,
                ],
            ],
            'networks' => [
                $destination->network => [
                    'external' => true,
                    'name' => $destination->network,
                    'attachable' => true,
                ],
            ],
        ];

        if ($application->settings->custom_internal_name) {
            $dockerCompose['services'][$containerName]['networks'][$destination->network]['aliases'][] = $application->settings->custom_internal_name;
        }

        $envFileName = $config->getEnvFileName();
        if (strlen($envFileName) > 0) {
            $dockerCompose['services'][$containerName]['env_file'] = [$envFileName];
        }

        if (! is_null($application->limits_cpuset)) {
            $dockerCompose['services'][$containerName]['cpuset'] = $application->limits_cpuset;
        }

        if ($this->deploymentAction->getContext()->getCurrentServer()->isSwarm()) {
            $keysToUnset = [
                'container_name',
                'expose',
                'restart',
                'mem_limit',
                'memswap_limit',
                'mem_swappiness',
                'mem_reservation',
                'cpus',
                'cpuset',
                'cpu_shares',
            ];

            foreach ($keysToUnset as $keyToUnset) {
                unset($dockerCompose['services'][$containerName][$keyToUnset]);
            }

            $dockerCompose['services'][$containerName]['deploy'] = [
                'mode' => 'replicated',
                'replicas' => $application->swarm_replicas ?? 1,
                'update_config' => [
                    'order' => 'start-first',
                ],
                'rollback_config' => [
                    'order' => 'start-first',
                ],
                'labels' => $labels,
                'resources' => [
                    'limits' => [
                        'cpus' => $application->limits_cpus,
                        'memory' => $application->limits_memory,
                    ],
                    'reservations' => [
                        'cpus' => $application->limits_cpus,
                        'memory' => $application->limits_memory,
                    ],
                ],
            ];

            if ($application->settings->is_swarm_only_worker_nodes) {
                $dockerCompose['services'][$containerName]['deploy']['placement'] = [
                    'constraints' => [
                        'node.role == worker',
                    ],
                ];
            }

            if ($this->deploymentAction->getContext()->getApplicationDeploymentQueue()->pull_request_id !== 0) {
                $dockerCompose['services'][$containerName]['deploy']['replicas'] = 1;
            }
        } else {
            $dockerCompose['services'][$containerName]['labels'] = $labels;
        }

        if ($this->deploymentAction->getContext()->getCurrentServer()->isLogDrainEnabled() && $application->isLogDrainEnabled()) {
            $dockerCompose['services'][$containerName]['logging'] = [
                'driver' => 'fluentd',
                'options' => [
                    'fluentd-address' => 'tcp://127.0.0.1:24224',
                    'fluentd-async' => 'true',
                    'fluentd-sub-second-precision' => 'true',
                ],
            ];
        }

        if ($application->settings->is_gpu_enabled) {
            $dockerCompose['services'][$containerName]['deploy']['resources']['reservations']['devices'] = [
                [
                    'driver' => $application->settings->gpu_driver ?? 'nvidia',
                    'capabilities' => ['gpu'],
                    'options' => $application->settings->gpu_options ?? [],
                ],
            ];

            if ($count = $application->settings->gpu_count) {
                $dockerCompose['services'][$containerName]['deploy']['resources']['reservations']['devices'][0]['count'] = ($count === 'all' ? 'all' : (int) $count);
            } elseif ($application->settings->gpu_device_ids) {
                $dockerCompose['services'][$containerName]['deploy']['resources']['reservations']['devices'][0]['ids'] = $application->settings->gpu_device_ids;
            }
        }

        if ($application->isHealthcheckDisabled()) {
            unset($dockerCompose['services'][$containerName]['healthcheck']);
        }

        if (count($application->ports_mappings_array) > 0 && $pullRequestId === 0) {
            $dockerCompose['services'][$containerName]['ports'] = $application->ports_mappings_array;
        }

        if (count($persistentStorages) > 0) {
            $dockerCompose['services'][$containerName]['volumes'] = $persistentStorages;
        }

        if (count($persistentFileVolumes) > 0) {
            $dockerCompose['services'][$containerName]['volumes'] = $persistentFileVolumes->map(function ($item) {
                return "$item->fs_path:$item->mount_path";
            })->toArray();
        }

        if (count($volumeNames) > 0) {
            $dockerCompose['volumes'] = $volumeNames;
        }

        if ($pullRequestId === 0) {
            $customCompose = convert_docker_run_to_compose($application->custom_docker_run_options);

            $perhapsCustomContainerName = $application->settings->is_consistent_container_name_enabled ? $application->uuid : $containerName;
            if ($application->settings->is_consistent_container_name_enabled) {
                $dockerCompose['services'][$application->uuid] = $dockerCompose['services'][$containerName];
            }

            if (count($customCompose) > 0) {
                $ipv4 = data_get($customCompose, 'ip.0');
                $ipv6 = data_get($customCompose, 'ip6.0');
                data_forget($customCompose, 'ip');
                data_forget($customCompose, 'ip6');

                if ($ipv4 || $ipv6) {
                    unset($dockerCompose['services'][$perhapsCustomContainerName]['networks']);
                }

                if ($ipv4) {
                    $dockerCompose['services'][$perhapsCustomContainerName]['networks'] = [
                        $destination->network => [
                            'ipv4_address' => $ipv4,
                        ],
                    ];
                }

                if ($ipv6) {
                    $dockerCompose['services'][$perhapsCustomContainerName]['networks'] = [
                        $destination->network => [
                            'ipv6_address' => $ipv6,
                        ],
                    ];
                }

                $dockerCompose['services'][$perhapsCustomContainerName] = array_merge($dockerCompose['services'][$perhapsCustomContainerName], $customCompose);
            }
        }

        return $dockerCompose;
    }

    public function writeEnvironmentVariables()
    {
        $environmentVariables = $this->generateEnvironmentVariables();
        $base64envs = base64_encode($environmentVariables->implode("\n"));

        $applicationDeploymentQueue = $this->deploymentAction->getContext()->getApplicationDeploymentQueue();

        $config = $this->deploymentAction->getContext()->getDeploymentConfig();

        $workDir = $config->getWorkDir();

        $this->saveEnvironmentVariablesToServer($applicationDeploymentQueue, $base64envs, $workDir, $config);

    }
}
