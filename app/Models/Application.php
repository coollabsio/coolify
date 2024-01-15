<?php

namespace App\Models;

use App\Enums\ApplicationDeploymentStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Visus\Cuid2\Cuid2;

class Application extends BaseModel
{
    use SoftDeletes;
    protected $guarded = [];

    protected static function booted()
    {
        static::saving(function ($application) {
            if ($application->fqdn == '') {
                $application->fqdn = null;
            }
            $application->forceFill([
                'fqdn' => $application->fqdn,
                'install_command' => Str::of($application->install_command)->trim(),
                'build_command' => Str::of($application->build_command)->trim(),
                'start_command' => Str::of($application->start_command)->trim(),
                'base_directory' => Str::of($application->base_directory)->trim(),
                'publish_directory' => Str::of($application->publish_directory)->trim(),
            ]);
        });
        static::created(function ($application) {
            ApplicationSetting::create([
                'application_id' => $application->id,
            ]);
        });
        static::deleting(function ($application) {
            $application->settings()->delete();
            $storages = $application->persistentStorages()->get();
            $server = data_get($application, 'destination.server');
            if ($server) {
                foreach ($storages as $storage) {
                    instant_remote_process(["docker volume rm -f $storage->name"], $server, false);
                }
            }
            $application->persistentStorages()->delete();
            $application->environment_variables()->delete();
            $application->environment_variables_preview()->delete();
        });
    }
    // Build packs / deployment types


    public function servers(): Collection
    {
        $mainServer = data_get($this, 'destination.server');
        $additionalDestinations = data_get($this, 'additional_destinations', null);
        $additionalServers = collect([]);
        if ($this->isMultipleServerDeployment()) {
            ray('asd');
            if (str($additionalDestinations)->isNotEmpty()) {
                $additionalDestinations = str($additionalDestinations)->explode(',');
                foreach ($additionalDestinations as $destinationId) {
                    $destination = StandaloneDocker::find($destinationId)->whereNot('id', $mainServer->id)->first();
                    $server = data_get($destination, 'server');
                    $additionalServers->push($server);
                }
            }
        }
        return collect([$mainServer])->merge($additionalServers);
    }

    public function generateImageNames(string $commit, int $pullRequestId)
    {
        if ($this->dockerfile) {
            if ($this->docker_registry_image_name) {
                $buildImageName = Str::lower("{$this->docker_registry_image_name}:build");
                $productionImageName = Str::lower("{$this->docker_registry_image_name}:latest");
            } else {
                $buildImageName = Str::lower("{$this->uuid}:build");
                $productionImageName = Str::lower("{$this->uuid}:latest");
            }
        } else if ($this->build_pack === 'dockerimage') {
            $productionImageName = Str::lower("{$this->docker_registry_image_name}:{$this->docker_registry_image_tag}");
        } else if ($pullRequestId === 0) {
            $dockerImageTag = str($commit)->substr(0, 128);
            if ($this->docker_registry_image_name) {
                $buildImageName = Str::lower("{$this->docker_registry_image_name}:{$dockerImageTag}-build");
                $productionImageName = Str::lower("{$this->docker_registry_image_name}:{$dockerImageTag}");
            } else {
                $buildImageName = Str::lower("{$this->uuid}:{$dockerImageTag}-build");
                $productionImageName = Str::lower("{$this->uuid}:{$dockerImageTag}");
            }
        } else if ($pullRequestId !== 0) {
            if ($this->docker_registry_image_name) {
                $buildImageName = Str::lower("{$this->docker_registry_image_name}:pr-{$pullRequestId}-build");
                $productionImageName = Str::lower("{$this->docker_registry_image_name}:pr-{$pullRequestId}");
            } else {
                $buildImageName = Str::lower("{$this->uuid}:pr-{$pullRequestId}-build");
                $productionImageName = Str::lower("{$this->uuid}:pr-{$pullRequestId}");
            }
        }
        return [
            'buildImageName' => $buildImageName,
            'productionImageName' => $productionImageName,
        ];
    }
    // End of build packs / deployment types

    public function link()
    {
        if (data_get($this, 'environment.project.uuid')) {
            return route('project.application.configuration', [
                'project_uuid' => data_get($this, 'environment.project.uuid'),
                'environment_name' => data_get($this, 'environment.name'),
                'application_uuid' => data_get($this, 'uuid')
            ]);
        }
        return null;
    }
    public function settings()
    {
        return $this->hasOne(ApplicationSetting::class);
    }

    public function persistentStorages()
    {
        return $this->morphMany(LocalPersistentVolume::class, 'resource');
    }
    public function fileStorages()
    {
        return $this->morphMany(LocalFileVolume::class, 'resource');
    }

    public function type()
    {
        return 'application';
    }

    public function publishDirectory(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value ? '/' . ltrim($value, '/') : null,
        );
    }

    public function gitBranchLocation(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (!is_null($this->source?->html_url) && !is_null($this->git_repository) && !is_null($this->git_branch)) {
                    return "{$this->source->html_url}/{$this->git_repository}/tree/{$this->git_branch}";
                }
                return $this->git_repository;
            }

        );
    }

    public function gitWebhook(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (!is_null($this->source?->html_url) && !is_null($this->git_repository) && !is_null($this->git_branch)) {
                    return "{$this->source->html_url}/{$this->git_repository}/settings/hooks";
                }
                return $this->git_repository;
            }
        );
    }

    public function gitCommits(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (!is_null($this->source?->html_url) && !is_null($this->git_repository) && !is_null($this->git_branch)) {
                    return "{$this->source->html_url}/{$this->git_repository}/commits/{$this->git_branch}";
                }
                return $this->git_repository;
            }
        );
    }
    public function dockerfileLocation(): Attribute
    {
        return Attribute::make(
            set: function ($value) {
                if (is_null($value) || $value === '') {
                    return '/Dockerfile';
                } else {
                    if ($value !== '/') {
                        return Str::start(Str::replaceEnd('/', '', $value), '/');
                    }
                    return Str::start($value, '/');
                }
            }
        );
    }
    public function dockerComposeLocation(): Attribute
    {
        return Attribute::make(
            set: function ($value) {
                if (is_null($value) || $value === '') {
                    return '/docker-compose.yaml';
                } else {
                    if ($value !== '/') {
                        return Str::start(Str::replaceEnd('/', '', $value), '/');
                    }
                    return Str::start($value, '/');
                }
            }
        );
    }
    public function dockerComposePrLocation(): Attribute
    {
        return Attribute::make(
            set: function ($value) {
                if (is_null($value) || $value === '') {
                    return '/docker-compose.yaml';
                } else {
                    if ($value !== '/') {
                        return Str::start(Str::replaceEnd('/', '', $value), '/');
                    }
                    return Str::start($value, '/');
                }
            }
        );
    }
    public function baseDirectory(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => '/' . ltrim($value, '/'),
        );
    }

    public function portsMappings(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value === "" ? null : $value,
        );
    }

    // Normal Deployments

    public function portsMappingsArray(): Attribute
    {
        return Attribute::make(
            get: fn () => is_null($this->ports_mappings)
                ? []
                : explode(',', $this->ports_mappings),

        );
    }

    public function portsExposesArray(): Attribute
    {
        return Attribute::make(
            get: fn () => is_null($this->ports_exposes)
                ? []
                : explode(',', $this->ports_exposes)
        );
    }
    public function serviceType()
    {
        $found = str(collect(SPECIFIC_SERVICES)->filter(function ($service) {
            return str($this->image)->before(':')->value() === $service;
        })->first());
        if ($found->isNotEmpty()) {
            return $found;
        }
        return null;
    }
    public function environment_variables(): HasMany
    {
        return $this->hasMany(EnvironmentVariable::class)->where('is_preview', false)->orderBy('key', 'asc');
    }

    public function runtime_environment_variables(): HasMany
    {
        return $this->hasMany(EnvironmentVariable::class)->where('is_preview', false)->where('key', 'not like', 'NIXPACKS_%');
    }

    // Preview Deployments

    public function build_environment_variables(): HasMany
    {
        return $this->hasMany(EnvironmentVariable::class)->where('is_preview', false)->where('is_build_time', true)->where('key', 'not like', 'NIXPACKS_%');
    }

    public function nixpacks_environment_variables(): HasMany
    {
        return $this->hasMany(EnvironmentVariable::class)->where('is_preview', false)->where('key', 'like', 'NIXPACKS_%');
    }

    public function environment_variables_preview(): HasMany
    {
        return $this->hasMany(EnvironmentVariable::class)->where('is_preview', true)->orderBy('key', 'asc');
    }

    public function runtime_environment_variables_preview(): HasMany
    {
        return $this->hasMany(EnvironmentVariable::class)->where('is_preview', true)->where('key', 'not like', 'NIXPACKS_%');
    }

    public function build_environment_variables_preview(): HasMany
    {
        return $this->hasMany(EnvironmentVariable::class)->where('is_preview', true)->where('is_build_time', true)->where('key', 'not like', 'NIXPACKS_%');
    }

    public function nixpacks_environment_variables_preview(): HasMany
    {
        return $this->hasMany(EnvironmentVariable::class)->where('is_preview', true)->where('key', 'like', 'NIXPACKS_%');
    }

    public function scheduled_tasks(): HasMany
    {
        return $this->hasMany(ScheduledTask::class)->orderBy('name', 'asc');
    }

    public function private_key()
    {
        return $this->belongsTo(PrivateKey::class);
    }

    public function environment()
    {
        return $this->belongsTo(Environment::class);
    }

    public function previews()
    {
        return $this->hasMany(ApplicationPreview::class);
    }

    public function destination()
    {
        return $this->morphTo();
    }

    public function source()
    {
        return $this->morphTo();
    }
    public function isDeploymentInprogress()
    {
        $deployments = ApplicationDeploymentQueue::where('application_id', $this->id)->where('status', ApplicationDeploymentStatus::IN_PROGRESS)->where('status', ApplicationDeploymentStatus::QUEUED)->count();
        if ($deployments > 0) {
            return true;
        }
        return false;
    }

    public function deployments(int $skip = 0, int $take = 10)
    {
        $deployments = ApplicationDeploymentQueue::where('application_id', $this->id)->orderBy('created_at', 'desc');
        $count = $deployments->count();
        $deployments = $deployments->skip($skip)->take($take)->get();
        return [
            'count' => $count,
            'deployments' => $deployments
        ];
    }

    public function get_deployment(string $deployment_uuid)
    {
        return Activity::where('subject_id', $this->id)->where('properties->type_uuid', '=', $deployment_uuid)->first();
    }

    public function isDeployable(): bool
    {
        if ($this->settings->is_auto_deploy_enabled) {
            return true;
        }
        return false;
    }

    public function isPRDeployable(): bool
    {
        if ($this->settings->is_preview_deployments_enabled) {
            return true;
        }
        return false;
    }

    public function deploymentType()
    {
        if (isDev() && data_get($this, 'private_key_id') === 0) {
            return 'deploy_key';
        }
        if (data_get($this, 'private_key_id')) {
            return 'deploy_key';
        } else if (data_get($this, 'source')) {
            return 'source';
        } else {
            return 'other';
        }
        throw new \Exception('No deployment type found');
    }
    public function could_set_build_commands(): bool
    {
        if ($this->build_pack === 'nixpacks') {
            return true;
        }
        return false;
    }
    public function git_based(): bool
    {
        if ($this->dockerfile) {
            return false;
        }
        if ($this->build_pack === 'dockerimage') {
            return false;
        }
        return true;
    }
    public function isHealthcheckDisabled(): bool
    {
        if (data_get($this, 'health_check_enabled') === false) {
            return true;
        }
        return false;
    }
    public function isLogDrainEnabled()
    {
        return data_get($this, 'settings.is_log_drain_enabled', false);
    }
    public function isConfigurationChanged($save = false)
    {
        $newConfigHash = $this->fqdn . $this->git_repository . $this->git_branch . $this->git_commit_sha . $this->build_pack . $this->static_image . $this->install_command  . $this->build_command . $this->start_command . $this->port_exposes . $this->port_mappings . $this->base_directory . $this->publish_directory . $this->dockerfile . $this->dockerfile_location . $this->custom_labels;
        if ($this->pull_request_id === 0 || $this->pull_request_id === null) {
            $newConfigHash .= json_encode($this->environment_variables->all());
        } else {
            $newConfigHash .= json_encode($this->environment_variables_preview->all());
        }
        $newConfigHash = md5($newConfigHash);
        $oldConfigHash = data_get($this, 'config_hash');
        if ($oldConfigHash === null) {
            if ($save) {
                $this->config_hash = $newConfigHash;
                $this->save();
            }
            return true;
        }
        if ($oldConfigHash === $newConfigHash) {
            return false;
        } else {
            if ($save) {
                $this->config_hash = $newConfigHash;
                $this->save();
            }
            return true;
        }
    }
    public function isMultipleServerDeployment()
    {
        return false;
        if (data_get($this, 'additional_destinations') && data_get($this, 'docker_registry_image_name')) {
            return true;
        }
        return false;
    }
    public function healthCheckUrl()
    {
        if ($this->dockerfile || $this->build_pack === 'dockerfile' || $this->build_pack === 'dockerimage') {
            return null;
        }
        if (!$this->health_check_port) {
            $health_check_port = $this->ports_exposes_array[0];
        } else {
            $health_check_port = $this->health_check_port;
        }
        if ($this->health_check_path) {
            $full_healthcheck_url = "{$this->health_check_scheme}://{$this->health_check_host}:{$health_check_port}{$this->health_check_path}";
        } else {
            $full_healthcheck_url = "{$this->health_check_scheme}://{$this->health_check_host}:{$health_check_port}/";
        }
        return $full_healthcheck_url;
    }
    function customRepository()
    {
        preg_match('/(?<=:)\d+(?=\/)/', $this->git_repository, $matches);
        $port = 22;
        if (count($matches) === 1) {
            $port = $matches[0];
            $gitHost = str($this->git_repository)->before(':');
            $gitRepo = str($this->git_repository)->after('/');
            $repository = "$gitHost:$gitRepo";
        } else {
            $repository = $this->git_repository;
        }
        return [
            'repository' => $repository,
            'port' => $port
        ];
    }
    function generateBaseDir(string $uuid)
    {
        return "/artifacts/{$uuid}";
    }
    function generateHealthCheckCommands()
    {
        if ($this->dockerfile || $this->build_pack === 'dockerfile' || $this->build_pack === 'dockerimage') {
            // TODO: disabled HC because there are several ways to hc a simple docker image, hard to figure out a good way. Like some docker images (pocketbase) does not have curl.
            return 'exit 0';
        }
        if (!$this->health_check_port) {
            $health_check_port = $this->ports_exposes_array[0];
        } else {
            $health_check_port = $this->health_check_port;
        }
        if ($this->health_check_path) {
            $this->full_healthcheck_url = "{$this->health_check_method}: {$this->health_check_scheme}://{$this->health_check_host}:{$health_check_port}{$this->health_check_path}";
            $generated_healthchecks_commands = [
                "curl -s -X {$this->health_check_method} -f {$this->health_check_scheme}://{$this->health_check_host}:{$health_check_port}{$this->health_check_path} > /dev/null"
            ];
        } else {
            $this->full_healthcheck_url = "{$this->health_check_method}: {$this->health_check_scheme}://{$this->health_check_host}:{$health_check_port}/";
            $generated_healthchecks_commands = [
                "curl -s -X {$this->health_check_method} -f {$this->health_check_scheme}://{$this->health_check_host}:{$health_check_port}/"
            ];
        }
        return implode(' ', $generated_healthchecks_commands);
    }
    function generateLocalPersistentVolumes(int $pullRequestId)
    {
        $persistentStorages = [];
        $volumeNames = [];
        foreach ($this->persistentStorages as $persistentStorage) {
            $volume_name = $persistentStorage->host_path ?? $persistentStorage->name;
            if ($pullRequestId !== 0) {
                $volume_name = $volume_name . '-pr-' . $pullRequestId;
            }
            $persistentStorages[] = $volume_name . ':' . $persistentStorage->mount_path;

            if ($persistentStorage->host_path) {
                continue;
            }

            $name = $persistentStorage->name;

            if ($pullRequestId !== 0) {
                $name = $name . '-pr-' . $pullRequestId;
            }

            $volumeNames[$name] = [
                'name' => $name,
                'external' => false,
            ];
        }

        return [
            'persistentStorages' => $persistentStorages,
            'volumeNames' => $volumeNames,
        ];
    }
    public function generateEnvironmentVariables($ports)
    {
        $environmentVariables = collect();
        // ray('Generate Environment Variables')->green();
        if ($this->pull_request_id === 0) {
            // ray($this->runtime_environment_variables)->green();
            foreach ($this->runtime_environment_variables as $env) {
                $environmentVariables->push("$env->key=$env->value");
            }
            foreach ($this->nixpacks_environment_variables as $env) {
                $environmentVariables->push("$env->key=$env->value");
            }
        } else {
            // ray($this->runtime_environment_variables_preview)->green();
            foreach ($this->runtime_environment_variables_preview as $env) {
                $environmentVariables->push("$env->key=$env->value");
            }
            foreach ($this->nixpacks_environment_variables_preview as $env) {
                $environmentVariables->push("$env->key=$env->value");
            }
        }
        // Add PORT if not exists, use the first port as default
        if ($environmentVariables->filter(fn ($env) => Str::of($env)->contains('PORT'))->isEmpty()) {
            $environmentVariables->push("PORT={$ports[0]}");
        }
        return $environmentVariables->all();
    }
    function generateDockerComposeFile(Server $server, ApplicationDeploymentQueue $deployment, string $workdir)
    {
        $pullRequestId = $deployment->pull_request_id;
        $ports = $this->settings->is_static ? [80] : $this->ports_exposes_array;
        $container_name = generateApplicationContainerName($this, $this->pull_request_id);
        $commit = str($deployment->getOutput('git_commit_sha'))->before("\t");

        [
            'productionImageName' => $productionImageName
        ] = $this->generateImageNames($commit, $pullRequestId);

        [
            'persistentStorages' => $persistentStorages,
            'volumeNames' => $volumeNames
        ] = $this->generateLocalPersistentVolumes($pullRequestId);

        $environmentVariables = $this->generateEnvironmentVariables($ports);

        if (data_get($this, 'custom_labels')) {
            $labels = collect(str($this->custom_labels)->explode(','));
            $labels = $labels->filter(function ($value, $key) {
                return !Str::startsWith($value, 'coolify.');
            });
            $this->custom_labels = $labels->implode(',');
            $this->save();
        } else {
            $labels = collect(generateLabelsApplication($this, $this->preview));
        }
        if ($this->pull_request_id !== 0) {
            $labels = collect(generateLabelsApplication($this, $this->preview));
        }
        $labels = $labels->merge(defaultLabels($this->id, $this->uuid, $this->pull_request_id))->toArray();
        $docker_compose = [
            'version' => '3.8',
            'services' => [
                $container_name => [
                    'image' => $productionImageName,
                    'container_name' => $container_name,
                    'restart' => RESTART_MODE,
                    'environment' => $environmentVariables,
                    'expose' => $ports,
                    'networks' => [
                        $this->destination->network,
                    ],
                    'healthcheck' => [
                        'test' => [
                            'CMD-SHELL',
                            $this->generateHealthCheckCommands()
                        ],
                        'interval' => $this->health_check_interval . 's',
                        'timeout' => $this->health_check_timeout . 's',
                        'retries' => $this->health_check_retries,
                        'start_period' => $this->health_check_start_period . 's'
                    ],
                    'mem_limit' => $this->limits_memory,
                    'memswap_limit' => $this->limits_memory_swap,
                    'mem_swappiness' => $this->limits_memory_swappiness,
                    'mem_reservation' => $this->limits_memory_reservation,
                    'cpus' => (float) $this->limits_cpus,
                    'cpu_shares' => $this->limits_cpu_shares,
                ]
            ],
            'networks' => [
                $this->destination->network => [
                    'external' => true,
                    'name' => $this->destination->network,
                    'attachable' => true
                ]
            ]
        ];
        if (!is_null($this->limits_cpuset)) {
            data_set($docker_compose, "services.{$container_name}.cpuset", $this->limits_cpuset);
        }
        if ($server->isSwarm()) {
            data_forget($docker_compose, 'services.' . $container_name . '.container_name');
            data_forget($docker_compose, 'services.' . $container_name . '.expose');
            data_forget($docker_compose, 'services.' . $container_name . '.restart');

            data_forget($docker_compose, 'services.' . $container_name . '.mem_limit');
            data_forget($docker_compose, 'services.' . $container_name . '.memswap_limit');
            data_forget($docker_compose, 'services.' . $container_name . '.mem_swappiness');
            data_forget($docker_compose, 'services.' . $container_name . '.mem_reservation');
            data_forget($docker_compose, 'services.' . $container_name . '.cpus');
            data_forget($docker_compose, 'services.' . $container_name . '.cpuset');
            data_forget($docker_compose, 'services.' . $container_name . '.cpu_shares');

            $docker_compose['services'][$container_name]['deploy'] = [
                'placement' => [
                    'constraints' => [
                        'node.role == worker'
                    ]
                ],
                'mode' => 'replicated',
                'replicas' => 1,
                'update_config' => [
                    'order' => 'start-first'
                ],
                'rollback_config' => [
                    'order' => 'start-first'
                ],
                'labels' => $labels,
                'resources' => [
                    'limits' => [
                        'cpus' => $this->limits_cpus,
                        'memory' => $this->limits_memory,
                    ],
                    'reservations' => [
                        'cpus' => $this->limits_cpus,
                        'memory' => $this->limits_memory,
                    ]
                ]
            ];
        } else {
            $docker_compose['services'][$container_name]['labels'] = $labels;
        }
        if ($server->isLogDrainEnabled() && $this->isLogDrainEnabled()) {
            $docker_compose['services'][$container_name]['logging'] = [
                'driver' => 'fluentd',
                'options' => [
                    'fluentd-address' => "tcp://127.0.0.1:24224",
                    'fluentd-async' => "true",
                    'fluentd-sub-second-precision' => "true",
                ]
            ];
        }
        if ($this->settings->is_gpu_enabled) {
            $docker_compose['services'][$container_name]['deploy']['resources']['reservations']['devices'] = [
                [
                    'driver' => data_get($this, 'settings.gpu_driver', 'nvidia'),
                    'capabilities' => ['gpu'],
                    'options' => data_get($this, 'settings.gpu_options', [])
                ]
            ];
            if (data_get($this, 'settings.gpu_count')) {
                $count = data_get($this, 'settings.gpu_count');
                if ($count === 'all') {
                    $docker_compose['services'][$container_name]['deploy']['resources']['reservations']['devices'][0]['count'] = $count;
                } else {
                    $docker_compose['services'][$container_name]['deploy']['resources']['reservations']['devices'][0]['count'] = (int) $count;
                }
            } else if (data_get($this, 'settings.gpu_device_ids')) {
                $docker_compose['services'][$container_name]['deploy']['resources']['reservations']['devices'][0]['ids'] = data_get($this, 'settings.gpu_device_ids');
            }
        }
        if ($this->isHealthcheckDisabled()) {
            data_forget($docker_compose, 'services.' . $container_name . '.healthcheck');
        }
        if (count($this->ports_mappings_array) > 0 && $this->pull_request_id === 0) {
            $docker_compose['services'][$container_name]['ports'] = $this->ports_mappings_array;
        }
        if (count($persistentStorages) > 0) {
            $docker_compose['services'][$container_name]['volumes'] = $persistentStorages;
        }
        if (count($volumeNames) > 0) {
            $docker_compose['volumes'] = $volumeNames;
        }

        $docker_compose['services'][$this->uuid] = $docker_compose['services'][$container_name];

        data_forget($docker_compose, 'services.' . $container_name);

        $docker_compose = Yaml::dump($docker_compose, 10);
        $docker_compose_base64 = base64_encode($docker_compose);
        $server->executeRemoteCommand(
            commands: collect([])->push([
                'command' => executeInDocker($deployment->deployment_uuid, "echo '{$docker_compose_base64}' | base64 -d > {$workdir}/docker-compose.yml"),
                'hidden' => true,
                'ignoreErrors' => true
            ]),
            loggingModel: $deployment
        );
    }
    function rollingUpdateApplication(Server $server, ApplicationDeploymentQueue $deployment, string $workdir)
    {
        $pullRequestId = $deployment->pull_request_id;
        $containerName = generateApplicationContainerName($this, $pullRequestId);
        // if (count($this->ports_mappings_array) > 0) {
        // $deployment->addLogEntry('Application has ports mapped to the host system, rolling update is not supported.');
        $containers = getCurrentApplicationContainerStatus($server, $this->id, $pullRequestId);
        // if ($pullRequestId === 0) {
        //     $containers = $containers->filter(function ($container) use ($containerName) {
        //         return data_get($container, 'Names') !== $containerName;
        //     });
        // }
        $containers->each(function ($container) use ($server, $deployment) {
            $removingContainerName = data_get($container, 'Names');
            $server->executeRemoteCommand(
                commands: collect([])->push([
                    'command' => "docker rm -f $removingContainerName",
                    'hidden' => true,
                    'ignoreErrors' => true
                ]),
                loggingModel: $deployment
            );
        });
        // }
        $server->executeRemoteCommand(
            commands: collect([])->push([
                'command' => executeInDocker($deployment->deployment_uuid, "docker compose --project-directory {$workdir} up --build -d"),
                'hidden' => true,
                'ignoreErrors' => true
            ]),
            loggingModel: $deployment
        );
        $deployment->addLogEntry("New container started.");
    }
    function setGitImportSettings(string $deployment_uuid, string $git_clone_command)
    {
        $baseDir = $this->generateBaseDir($deployment_uuid);
        if ($this->git_commit_sha !== 'HEAD') {
            $git_clone_command = "{$git_clone_command} && cd {$baseDir} && git -c advice.detachedHead=false checkout {$this->git_commit_sha} >/dev/null 2>&1";
        }
        if ($this->settings->is_git_submodules_enabled) {
            $git_clone_command = "{$git_clone_command} && cd {$baseDir} && git submodule update --init --recursive";
        }
        if ($this->settings->is_git_lfs_enabled) {
            $git_clone_command = "{$git_clone_command} && cd {$baseDir} && git lfs pull";
        }
        return $git_clone_command;
    }
    function generateGitImportCommands(string $deployment_uuid, int $pull_request_id = 0, ?string $git_type = null, bool $exec_in_docker = true, bool $only_checkout = false, ?string $custom_base_dir = null)
    {
        $branch = $this->git_branch;
        ['repository' => $customRepository, 'port' => $customPort] = $this->customRepository();
        $baseDir = $custom_base_dir ?? $this->generateBaseDir($deployment_uuid);
        $commands = collect([]);
        $git_clone_command = "git clone -b {$this->git_branch}";
        if ($only_checkout) {
            $git_clone_command = "git clone --no-checkout -b {$this->git_branch}";
        }
        if ($pull_request_id !== 0) {
            $pr_branch_name = "pr-{$pull_request_id}-coolify";
        }

        if ($this->deploymentType() === 'source') {
            $source_html_url = data_get($this, 'source.html_url');
            $url = parse_url(filter_var($source_html_url, FILTER_SANITIZE_URL));
            $source_html_url_host = $url['host'];
            $source_html_url_scheme = $url['scheme'];

            if ($this->source->getMorphClass() == 'App\Models\GithubApp') {
                if ($this->source->is_public) {
                    $fullRepoUrl = "{$this->source->html_url}/{$customRepository}";
                    $git_clone_command = "{$git_clone_command} {$this->source->html_url}/{$customRepository} {$baseDir}";
                    if (!$only_checkout) {
                        $git_clone_command = $this->setGitImportSettings($deployment_uuid, $git_clone_command);
                    }
                    if ($exec_in_docker) {
                        $commands->push(executeInDocker($deployment_uuid, $git_clone_command));
                    } else {
                        $commands->push($git_clone_command);
                    }
                } else {
                    $github_access_token = generate_github_installation_token($this->source);
                    if ($exec_in_docker) {
                        $commands->push(executeInDocker($deployment_uuid, "{$git_clone_command} $source_html_url_scheme://x-access-token:$github_access_token@$source_html_url_host/{$customRepository}.git {$baseDir}"));
                        $fullRepoUrl = "$source_html_url_scheme://x-access-token:$github_access_token@$source_html_url_host/{$customRepository}.git";
                    } else {
                        $commands->push("{$git_clone_command} $source_html_url_scheme://x-access-token:$github_access_token@$source_html_url_host/{$customRepository} {$baseDir}");
                        $fullRepoUrl = "$source_html_url_scheme://x-access-token:$github_access_token@$source_html_url_host/{$customRepository}";
                    }
                }
                if ($pull_request_id !== 0) {
                    $branch = "pull/{$pull_request_id}/head:$pr_branch_name";
                    if ($exec_in_docker) {
                        $commands->push(executeInDocker($deployment_uuid, "cd {$baseDir} && git fetch origin {$branch} && git checkout $pr_branch_name"));
                    } else {
                        $commands->push("cd {$baseDir} && git fetch origin {$branch} && git checkout $pr_branch_name");
                    }
                }
                return [
                    'commands' => $commands->implode(' && '),
                    'branch' => $branch,
                    'fullRepoUrl' => $fullRepoUrl
                ];
            }
        }
        if ($this->deploymentType() === 'deploy_key') {
            $fullRepoUrl = $customRepository;
            $private_key = data_get($this, 'private_key.private_key');
            if (is_null($private_key)) {
                throw new RuntimeException('Private key not found. Please add a private key to the application and try again.');
            }
            $private_key = base64_encode($private_key);
            $git_clone_command_base = "GIT_SSH_COMMAND=\"ssh -o ConnectTimeout=30 -p {$customPort} -o Port={$customPort} -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i /root/.ssh/id_rsa\" {$git_clone_command} {$customRepository} {$baseDir}";
            if ($only_checkout) {
                $git_clone_command = $git_clone_command_base;
            } else {
                $git_clone_command = $this->setGitImportSettings($deployment_uuid, $git_clone_command_base);
            }
            if ($exec_in_docker) {
                $commands = collect([
                    executeInDocker($deployment_uuid, "mkdir -p /root/.ssh"),
                    executeInDocker($deployment_uuid, "echo '{$private_key}' | base64 -d > /root/.ssh/id_rsa"),
                    executeInDocker($deployment_uuid, "chmod 600 /root/.ssh/id_rsa"),
                ]);
            } else {
                $commands = collect([
                    "mkdir -p /root/.ssh",
                    "echo '{$private_key}' | base64 -d > /root/.ssh/id_rsa",
                    "chmod 600 /root/.ssh/id_rsa",
                ]);
            }
            if ($pull_request_id !== 0) {
                if ($git_type === 'gitlab') {
                    $branch = "merge-requests/{$pull_request_id}/head:$pr_branch_name";
                    if ($exec_in_docker) {
                        $commands->push(executeInDocker($deployment_uuid, "echo 'Checking out $branch'"));
                    } else {
                        $commands->push("echo 'Checking out $branch'");
                    }
                    $git_clone_command = "{$git_clone_command} && cd {$baseDir} && GIT_SSH_COMMAND=\"ssh -o ConnectTimeout=30 -p {$customPort} -o Port={$customPort} -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i /root/.ssh/id_rsa\" git fetch origin $branch && git checkout $pr_branch_name";
                }
                if ($git_type === 'github') {
                    $branch = "pull/{$pull_request_id}/head:$pr_branch_name";
                    if ($exec_in_docker) {
                        $commands->push(executeInDocker($deployment_uuid, "echo 'Checking out $branch'"));
                    } else {
                        $commands->push("echo 'Checking out $branch'");
                    }
                    $git_clone_command = "{$git_clone_command} && cd {$baseDir} && GIT_SSH_COMMAND=\"ssh -o ConnectTimeout=30 -p {$customPort} -o Port={$customPort} -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i /root/.ssh/id_rsa\" git fetch origin $branch && git checkout $pr_branch_name";
                }
            }

            if ($exec_in_docker) {
                $commands->push(executeInDocker($deployment_uuid, $git_clone_command));
            } else {
                $commands->push($git_clone_command);
            }
            return [
                'commands' => $commands->implode(' && '),
                'branch' => $branch,
                'fullRepoUrl' => $fullRepoUrl
            ];
        }
        if ($this->deploymentType() === 'other') {
            $fullRepoUrl = $customRepository;
            $git_clone_command = "{$git_clone_command} {$customRepository} {$baseDir}";
            $git_clone_command = $this->setGitImportSettings($deployment_uuid, $git_clone_command);
            if ($exec_in_docker) {
                $commands->push(executeInDocker($deployment_uuid, $git_clone_command));
            } else {
                $commands->push($git_clone_command);
            }
            return [
                'commands' => $commands->implode(' && '),
                'branch' => $branch,
                'fullRepoUrl' => $fullRepoUrl
            ];
        }
    }
    public function prepareHelperImage(string $deploymentUuid)
    {
        $basedir = $this->generateBaseDir($deploymentUuid);
        $helperImage = config('coolify.helper_image');
        $server = data_get($this, 'destination.server');
        $network = data_get($this, 'destination.network');

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
    function parseCompose(int $pull_request_id = 0)
    {
        if ($this->docker_compose_raw) {
            $mainCompose = parseDockerComposeFile(resource: $this, isNew: false, pull_request_id: $pull_request_id);
            if ($this->getMorphClass() === 'App\Models\Application' && $this->docker_compose_pr_raw) {
                parseDockerComposeFile(resource: $this, isNew: false, pull_request_id: $pull_request_id, is_pr: true);
            }
            return $mainCompose;
        } else {
            return collect([]);
        }
    }
    function loadComposeFile($isInit = false)
    {
        $initialDockerComposeLocation = $this->docker_compose_location;
        if ($isInit && $this->docker_compose_raw) {
            return;
        }
        $uuid = new Cuid2();
        ['commands' => $cloneCommand] = $this->generateGitImportCommands(deployment_uuid: $uuid, only_checkout: true, exec_in_docker: false, custom_base_dir: '.');
        $workdir = rtrim($this->base_directory, '/');
        $composeFile = $this->docker_compose_location;
        // $prComposeFile = $this->docker_compose_pr_location;
        $fileList = collect([".$workdir$composeFile"]);
        // if ($composeFile !== $prComposeFile) {
        //     $fileList->push(".$prComposeFile");
        // }
        $commands = collect([
            "rm -rf /tmp/{$uuid}",
            "mkdir -p /tmp/{$uuid} && cd /tmp/{$uuid}",
            $cloneCommand,
            "git sparse-checkout init --cone",
            "git sparse-checkout set {$fileList->implode(' ')}",
            "git read-tree -mu HEAD",
            "cat .$workdir$composeFile",
        ]);
        $composeFileContent = instant_remote_process($commands, $this->destination->server, false);
        if (!$composeFileContent) {
            $this->docker_compose_location = $initialDockerComposeLocation;
            $this->save();
            throw new \Exception("Could not load base compose file from $workdir$composeFile");
        } else {
            $this->docker_compose_raw = $composeFileContent;
            $this->save();
        }
        // if ($composeFile === $prComposeFile) {
        //     $this->docker_compose_pr_raw = $composeFileContent;
        //     $this->save();
        // } else {
        //     $commands = collect([
        //         "cd /tmp/{$uuid}",
        //         "cat .$workdir$prComposeFile",
        //     ]);
        //     $composePrFileContent = instant_remote_process($commands, $this->destination->server, false);
        //     if (!$composePrFileContent) {
        //         $this->docker_compose_pr_location = $initialDockerComposePrLocation;
        //         $this->save();
        //         throw new \Exception("Could not load compose file from $workdir$prComposeFile");
        //     } else {
        //         $this->docker_compose_pr_raw = $composePrFileContent;
        //         $this->save();
        //     }
        // }

        $commands = collect([
            "rm -rf /tmp/{$uuid}",
        ]);
        instant_remote_process($commands, $this->destination->server, false);
        $parsedServices = $this->parseCompose();
        if ($this->docker_compose_domains) {
            $json = collect(json_decode($this->docker_compose_domains));
            $names = collect(data_get($parsedServices, 'services'))->keys()->toArray();
            $jsonNames = $json->keys()->toArray();
            $diff = array_diff($jsonNames, $names);
            $json = $json->filter(function ($value, $key) use ($diff) {
                return !in_array($key, $diff);
            });
            if ($json) {
                $this->docker_compose_domains = json_encode($json);
            } else {
                $this->docker_compose_domains = null;
            }
            $this->save();
        }
        return [
            'parsedServices' => $parsedServices,
            'initialDockerComposeLocation' => $this->docker_compose_location,
            'initialDockerComposePrLocation' => $this->docker_compose_pr_location,
        ];
    }
    function parseContainerLabels(?ApplicationPreview $preview = null)
    {
        $customLabels = data_get($this, 'custom_labels');
        if (!$customLabels) {
            return;
        }
        if (base64_encode(base64_decode($customLabels, true)) !== $customLabels) {
            ray('custom_labels is not base64 encoded');
            $this->custom_labels = str($customLabels)->replace(',', "\n");
            $this->custom_labels = base64_encode($customLabels);
        }
        $customLabels = base64_decode($this->custom_labels);
        if (mb_detect_encoding($customLabels, 'ASCII', true) === false) {
            ray('custom_labels contains non-ascii characters');
            $customLabels = str(implode("|", generateLabelsApplication($this, $preview)))->replace("|", "\n");
        }
        $this->custom_labels = base64_encode($customLabels);
        $this->save();
        return $customLabels;
    }
}
