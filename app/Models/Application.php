<?php

namespace App\Models;

use App\Enums\ApplicationDeploymentStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Url\Url;
use Symfony\Component\Yaml\Yaml;
use Visus\Cuid2\Cuid2;

#[OA\Schema(
    description: 'Application model',
    type: 'object',
    properties: [
        'id' => ['type' => 'integer', 'description' => 'The application identifier in the database.'],
        'description' => ['type' => 'string', 'nullable' => true, 'description' => 'The application description.'],
        'repository_project_id' => ['type' => 'integer', 'nullable' => true, 'description' => 'The repository project identifier.'],
        'uuid' => ['type' => 'string', 'description' => 'The application UUID.'],
        'name' => ['type' => 'string', 'description' => 'The application name.'],
        'fqdn' => ['type' => 'string', 'nullable' => true, 'description' => 'The application domains.'],
        'config_hash' => ['type' => 'string', 'description' => 'Configuration hash.'],
        'git_repository' => ['type' => 'string', 'description' => 'Git repository URL.'],
        'git_branch' => ['type' => 'string', 'description' => 'Git branch.'],
        'git_commit_sha' => ['type' => 'string', 'description' => 'Git commit SHA.'],
        'git_full_url' => ['type' => 'string', 'nullable' => true, 'description' => 'Git full URL.'],
        'docker_registry_image_name' => ['type' => 'string', 'nullable' => true, 'description' => 'Docker registry image name.'],
        'docker_registry_image_tag' => ['type' => 'string', 'nullable' => true, 'description' => 'Docker registry image tag.'],
        'build_pack' => ['type' => 'string', 'description' => 'Build pack.', 'enum' => ['nixpacks', 'static', 'dockerfile', 'dockercompose']],
        'static_image' => ['type' => 'string', 'description' => 'Static image used when static site is deployed.'],
        'install_command' => ['type' => 'string', 'description' => 'Install command.'],
        'build_command' => ['type' => 'string', 'description' => 'Build command.'],
        'start_command' => ['type' => 'string', 'description' => 'Start command.'],
        'ports_exposes' => ['type' => 'string', 'description' => 'Ports exposes.'],
        'ports_mappings' => ['type' => 'string', 'nullable' => true, 'description' => 'Ports mappings.'],
        'base_directory' => ['type' => 'string', 'description' => 'Base directory for all commands.'],
        'publish_directory' => ['type' => 'string', 'description' => 'Publish directory.'],
        'health_check_enabled' => ['type' => 'boolean', 'description' => 'Health check enabled.'],
        'health_check_path' => ['type' => 'string', 'description' => 'Health check path.'],
        'health_check_port' => ['type' => 'string', 'nullable' => true, 'description' => 'Health check port.'],
        'health_check_host' => ['type' => 'string', 'nullable' => true, 'description' => 'Health check host.'],
        'health_check_method' => ['type' => 'string', 'description' => 'Health check method.'],
        'health_check_return_code' => ['type' => 'integer', 'description' => 'Health check return code.'],
        'health_check_scheme' => ['type' => 'string', 'description' => 'Health check scheme.'],
        'health_check_response_text' => ['type' => 'string', 'nullable' => true, 'description' => 'Health check response text.'],
        'health_check_interval' => ['type' => 'integer', 'description' => 'Health check interval in seconds.'],
        'health_check_timeout' => ['type' => 'integer', 'description' => 'Health check timeout in seconds.'],
        'health_check_retries' => ['type' => 'integer', 'description' => 'Health check retries count.'],
        'health_check_start_period' => ['type' => 'integer', 'description' => 'Health check start period in seconds.'],
        'limits_memory' => ['type' => 'string', 'description' => 'Memory limit.'],
        'limits_memory_swap' => ['type' => 'string', 'description' => 'Memory swap limit.'],
        'limits_memory_swappiness' => ['type' => 'integer', 'description' => 'Memory swappiness.'],
        'limits_memory_reservation' => ['type' => 'string', 'description' => 'Memory reservation.'],
        'limits_cpus' => ['type' => 'string', 'description' => 'CPU limit.'],
        'limits_cpuset' => ['type' => 'string', 'nullable' => true, 'description' => 'CPU set.'],
        'limits_cpu_shares' => ['type' => 'integer', 'description' => 'CPU shares.'],
        'status' => ['type' => 'string', 'description' => 'Application status.'],
        'preview_url_template' => ['type' => 'string',  'description' => 'Preview URL template.'],
        'destination_type' => ['type' => 'string', 'description' => 'Destination type.'],
        'destination_id' => ['type' => 'integer', 'description' => 'Destination identifier.'],
        'source_id' => ['type' => 'integer', 'nullable' => true, 'description' => 'Source identifier.'],
        'private_key_id' => ['type' => 'integer', 'nullable' => true, 'description' => 'Private key identifier.'],
        'environment_id' => ['type' => 'integer', 'description' => 'Environment identifier.'],
        'dockerfile' => ['type' => 'string', 'nullable' => true, 'description' => 'Dockerfile content. Used for dockerfile build pack.'],
        'dockerfile_location' => ['type' => 'string', 'description' => 'Dockerfile location.'],
        'custom_labels' => ['type' => 'string', 'nullable' => true, 'description' => 'Custom labels.'],
        'dockerfile_target_build' => ['type' => 'string', 'nullable' => true, 'description' => 'Dockerfile target build.'],
        'manual_webhook_secret_github' => ['type' => 'string', 'nullable' => true, 'description' => 'Manual webhook secret for GitHub.'],
        'manual_webhook_secret_gitlab' => ['type' => 'string', 'nullable' => true, 'description' => 'Manual webhook secret for GitLab.'],
        'manual_webhook_secret_bitbucket' => ['type' => 'string', 'nullable' => true, 'description' => 'Manual webhook secret for Bitbucket.'],
        'manual_webhook_secret_gitea' => ['type' => 'string', 'nullable' => true, 'description' => 'Manual webhook secret for Gitea.'],
        'docker_compose_location' => ['type' => 'string', 'description' => 'Docker compose location.'],
        'docker_compose' => ['type' => 'string', 'nullable' => true, 'description' => 'Docker compose content. Used for docker compose build pack.'],
        'docker_compose_raw' => ['type' => 'string', 'nullable' => true, 'description' => 'Docker compose raw content.'],
        'docker_compose_domains' => ['type' => 'string', 'nullable' => true, 'description' => 'Docker compose domains.'],
        'docker_compose_custom_start_command' => ['type' => 'string', 'nullable' => true, 'description' => 'Docker compose custom start command.'],
        'docker_compose_custom_build_command' => ['type' => 'string', 'nullable' => true, 'description' => 'Docker compose custom build command.'],
        'swarm_replicas' => ['type' => 'integer', 'nullable' => true, 'description' => 'Swarm replicas. Only used for swarm deployments.'],
        'swarm_placement_constraints' => ['type' => 'string', 'nullable' => true, 'description' => 'Swarm placement constraints. Only used for swarm deployments.'],
        'custom_docker_run_options' => ['type' => 'string', 'nullable' => true, 'description' => 'Custom docker run options.'],
        'post_deployment_command' => ['type' => 'string', 'nullable' => true, 'description' => 'Post deployment command.'],
        'post_deployment_command_container' => ['type' => 'string', 'nullable' => true, 'description' => 'Post deployment command container.'],
        'pre_deployment_command' => ['type' => 'string', 'nullable' => true, 'description' => 'Pre deployment command.'],
        'pre_deployment_command_container' => ['type' => 'string', 'nullable' => true, 'description' => 'Pre deployment command container.'],
        'watch_paths' => ['type' => 'string', 'nullable' => true, 'description' => 'Watch paths.'],
        'custom_healthcheck_found' => ['type' => 'boolean', 'description' => 'Custom healthcheck found.'],
        'redirect' => ['type' => 'string', 'nullable' => true, 'description' => 'How to set redirect with Traefik / Caddy. www<->non-www.', 'enum' => ['www', 'non-www', 'both']],
        'created_at' => ['type' => 'string', 'format' => 'date-time', 'description' => 'The date and time when the application was created.'],
        'updated_at' => ['type' => 'string', 'format' => 'date-time', 'description' => 'The date and time when the application was last updated.'],
        'deleted_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true, 'description' => 'The date and time when the application was deleted.'],
        'compose_parsing_version' => ['type' => 'string', 'description' => 'How Coolify parse the compose file.'],
        'custom_nginx_configuration' => ['type' => 'string', 'nullable' => true, 'description' => 'Custom Nginx configuration base64 encoded.'],
    ]
)]

class Application extends BaseModel
{
    use HasFactory, SoftDeletes;

    private static $parserVersion = '4';

    protected $guarded = [];

    protected $appends = ['server_status'];

    protected static function booted()
    {
        static::addGlobalScope('withRelations', function ($builder) {
            $builder->withCount([
                'additional_servers',
                'additional_networks',
            ]);
        });
        static::saving(function ($application) {
            $payload = [];
            if ($application->isDirty('fqdn')) {
                if ($application->fqdn === '') {
                    $application->fqdn = null;
                }
                $payload['fqdn'] = $application->fqdn;
            }
            if ($application->isDirty('install_command')) {
                $payload['install_command'] = str($application->install_command)->trim();
            }
            if ($application->isDirty('build_command')) {
                $payload['build_command'] = str($application->build_command)->trim();
            }
            if ($application->isDirty('start_command')) {
                $payload['start_command'] = str($application->start_command)->trim();
            }
            if ($application->isDirty('base_directory')) {
                $payload['base_directory'] = str($application->base_directory)->trim();
            }
            if ($application->isDirty('publish_directory')) {
                $payload['publish_directory'] = str($application->publish_directory)->trim();
            }
            if ($application->isDirty('status')) {
                $payload['last_online_at'] = now();
            }
            if ($application->isDirty('custom_nginx_configuration')) {
                if ($application->custom_nginx_configuration === '') {
                    $payload['custom_nginx_configuration'] = null;
                }
            }
            if (count($payload) > 0) {
                $application->forceFill($payload);
            }
        });
        static::created(function ($application) {
            ApplicationSetting::create([
                'application_id' => $application->id,
            ]);
            $application->compose_parsing_version = self::$parserVersion;
            $application->save();
        });
        static::forceDeleting(function ($application) {
            $application->update(['fqdn' => null]);
            $application->settings()->delete();
            $application->persistentStorages()->delete();
            $application->environment_variables()->delete();
            $application->environment_variables_preview()->delete();
            foreach ($application->scheduled_tasks as $task) {
                $task->delete();
            }
            $application->tags()->detach();
            $application->previews()->delete();
            foreach ($application->deployment_queue as $deployment) {
                $deployment->delete();
            }
        });
    }

    public static function ownedByCurrentTeamAPI(int $teamId)
    {
        return Application::whereRelation('environment.project.team', 'id', $teamId)->orderBy('name');
    }

    public static function ownedByCurrentTeam()
    {
        return Application::whereRelation('environment.project.team', 'id', currentTeam()->id)->orderBy('name');
    }

    public function getContainersToStop(bool $previewDeployments = false): array
    {
        $containers = $previewDeployments
            ? getCurrentApplicationContainerStatus($this->destination->server, $this->id, includePullrequests: true)
            : getCurrentApplicationContainerStatus($this->destination->server, $this->id, 0);

        return $containers->pluck('Names')->toArray();
    }

    public function stopContainers(array $containerNames, $server, int $timeout = 600)
    {
        $processes = [];
        foreach ($containerNames as $containerName) {
            $processes[$containerName] = $this->stopContainer($containerName, $server, $timeout);
        }

        $startTime = time();
        while (count($processes) > 0) {
            $finishedProcesses = array_filter($processes, function ($process) {
                return ! $process->running();
            });
            foreach ($finishedProcesses as $containerName => $process) {
                unset($processes[$containerName]);
                $this->removeContainer($containerName, $server);
            }

            if (time() - $startTime >= $timeout) {
                $this->forceStopRemainingContainers(array_keys($processes), $server);
                break;
            }

            usleep(100000);
        }
    }

    public function stopContainer(string $containerName, $server, int $timeout): InvokedProcess
    {
        return Process::timeout($timeout)->start("docker stop --time=$timeout $containerName");
    }

    public function removeContainer(string $containerName, $server)
    {
        instant_remote_process(command: ["docker rm -f $containerName"], server: $server, throwError: false);
    }

    public function forceStopRemainingContainers(array $containerNames, $server)
    {
        foreach ($containerNames as $containerName) {
            instant_remote_process(command: ["docker kill $containerName"], server: $server, throwError: false);
            $this->removeContainer($containerName, $server);
        }
    }

    public function delete_configurations()
    {
        $server = data_get($this, 'destination.server');
        $workdir = $this->workdir();
        if (str($workdir)->endsWith($this->uuid)) {
            instant_remote_process(['rm -rf '.$this->workdir()], $server, false);
        }
    }

    public function delete_volumes(?Collection $persistentStorages)
    {
        if ($this->build_pack === 'dockercompose') {
            $server = data_get($this, 'destination.server');
            instant_remote_process(["cd {$this->dirOnServer()} && docker compose down -v"], $server, false);
        } else {
            if ($persistentStorages->count() === 0) {
                return;
            }
            $server = data_get($this, 'destination.server');
            foreach ($persistentStorages as $storage) {
                instant_remote_process(["docker volume rm -f $storage->name"], $server, false);
            }
        }
    }

    public function delete_connected_networks($uuid)
    {
        $server = data_get($this, 'destination.server');
        instant_remote_process(["docker network disconnect {$uuid} coolify-proxy"], $server, false);
        instant_remote_process(["docker network rm {$uuid}"], $server, false);
    }

    public function additional_servers()
    {
        return $this->belongsToMany(Server::class, 'additional_destinations')
            ->withPivot('standalone_docker_id', 'status');
    }

    public function additional_networks()
    {
        return $this->belongsToMany(StandaloneDocker::class, 'additional_destinations')
            ->withPivot('server_id', 'status');
    }

    public function is_public_repository(): bool
    {
        if (data_get($this, 'source.is_public')) {
            return true;
        }

        return false;
    }

    public function is_github_based(): bool
    {
        if (data_get($this, 'source')) {
            return true;
        }

        return false;
    }

    public function isForceHttpsEnabled()
    {
        return data_get($this, 'settings.is_force_https_enabled', false);
    }

    public function isStripprefixEnabled()
    {
        return data_get($this, 'settings.is_stripprefix_enabled', true);
    }

    public function isGzipEnabled()
    {
        return data_get($this, 'settings.is_gzip_enabled', true);
    }

    public function link()
    {
        if (data_get($this, 'environment.project.uuid')) {
            return route('project.application.configuration', [
                'project_uuid' => data_get($this, 'environment.project.uuid'),
                'environment_name' => data_get($this, 'environment.name'),
                'application_uuid' => data_get($this, 'uuid'),
            ]);
        }

        return null;
    }

    public function taskLink($task_uuid)
    {
        if (data_get($this, 'environment.project.uuid')) {
            $route = route('project.application.scheduled-tasks', [
                'project_uuid' => data_get($this, 'environment.project.uuid'),
                'environment_name' => data_get($this, 'environment.name'),
                'application_uuid' => data_get($this, 'uuid'),
                'task_uuid' => $task_uuid,
            ]);
            $settings = instanceSettings();
            if (data_get($settings, 'fqdn')) {
                $url = Url::fromString($route);
                $url = $url->withPort(null);
                $fqdn = data_get($settings, 'fqdn');
                $fqdn = str_replace(['http://', 'https://'], '', $fqdn);
                $url = $url->withHost($fqdn);

                return $url->__toString();
            }

            return $route;
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
            set: fn ($value) => $value ? '/'.ltrim($value, '/') : null,
        );
    }

    public function gitBranchLocation(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (! is_null($this->source?->html_url) && ! is_null($this->git_repository) && ! is_null($this->git_branch)) {
                    if (str($this->git_repository)->contains('bitbucket')) {
                        return "{$this->source->html_url}/{$this->git_repository}/src/{$this->git_branch}";
                    }

                    return "{$this->source->html_url}/{$this->git_repository}/tree/{$this->git_branch}";
                }
                // Convert the SSH URL to HTTPS URL
                if (strpos($this->git_repository, 'git@') === 0) {
                    $git_repository = str_replace(['git@', ':', '.git'], ['', '/', ''], $this->git_repository);

                    if (str($this->git_repository)->contains('bitbucket')) {
                        return "https://{$git_repository}/src/{$this->git_branch}";
                    }

                    return "https://{$git_repository}/tree/{$this->git_branch}";
                }

                return $this->git_repository;
            }
        );
    }

    public function gitWebhook(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (! is_null($this->source?->html_url) && ! is_null($this->git_repository) && ! is_null($this->git_branch)) {
                    return "{$this->source->html_url}/{$this->git_repository}/settings/hooks";
                }
                // Convert the SSH URL to HTTPS URL
                if (strpos($this->git_repository, 'git@') === 0) {
                    $git_repository = str_replace(['git@', ':', '.git'], ['', '/', ''], $this->git_repository);

                    return "https://{$git_repository}/settings/hooks";
                }

                return $this->git_repository;
            }
        );
    }

    public function gitCommits(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (! is_null($this->source?->html_url) && ! is_null($this->git_repository) && ! is_null($this->git_branch)) {
                    return "{$this->source->html_url}/{$this->git_repository}/commits/{$this->git_branch}";
                }
                // Convert the SSH URL to HTTPS URL
                if (strpos($this->git_repository, 'git@') === 0) {
                    $git_repository = str_replace(['git@', ':', '.git'], ['', '/', ''], $this->git_repository);

                    return "https://{$git_repository}/commits/{$this->git_branch}";
                }

                return $this->git_repository;
            }
        );
    }

    public function gitCommitLink($link): string
    {
        if (! is_null(data_get($this, 'source.html_url')) && ! is_null(data_get($this, 'git_repository')) && ! is_null(data_get($this, 'git_branch'))) {
            if (str($this->source->html_url)->contains('bitbucket')) {
                return "{$this->source->html_url}/{$this->git_repository}/commits/{$link}";
            }

            return "{$this->source->html_url}/{$this->git_repository}/commit/{$link}";
        }
        if (str($this->git_repository)->contains('bitbucket')) {
            $git_repository = str_replace('.git', '', $this->git_repository);
            $url = Url::fromString($git_repository);
            $url = $url->withUserInfo('');
            $url = $url->withPath($url->getPath().'/commits/'.$link);

            return $url->__toString();
        }
        if (strpos($this->git_repository, 'git@') === 0) {
            $git_repository = str_replace(['git@', ':', '.git'], ['', '/', ''], $this->git_repository);
            if (data_get($this, 'source.html_url')) {
                return "{$this->source->html_url}/{$git_repository}/commit/{$link}";
            }

            return "{$git_repository}/commit/{$link}";
        }

        return $this->git_repository;
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

    public function baseDirectory(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => '/'.ltrim($value, '/'),
        );
    }

    public function portsMappings(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value === '' ? null : $value,
        );
    }

    public function portsMappingsArray(): Attribute
    {
        return Attribute::make(
            get: fn () => is_null($this->ports_mappings)
                ? []
                : explode(',', $this->ports_mappings),

        );
    }

    public function isRunning()
    {
        return (bool) str($this->status)->startsWith('running');
    }

    public function isExited()
    {
        return (bool) str($this->status)->startsWith('exited');
    }

    public function realStatus()
    {
        return $this->getRawOriginal('status');
    }

    protected function serverStatus(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (! $this->relationLoaded('additional_servers') || $this->additional_servers->count() === 0) {
                    return $this->destination?->server?->isFunctional() ?? false;
                }

                $additional_servers_status = $this->additional_servers->pluck('pivot.status');
                $main_server_status = $this->destination?->server?->isFunctional() ?? false;

                foreach ($additional_servers_status as $status) {
                    $server_status = str($status)->before(':')->value();
                    if ($server_status !== 'running') {
                        return false;
                    }
                }

                return $main_server_status;
            }
        );
    }

    public function status(): Attribute
    {
        return Attribute::make(
            set: function ($value) {
                if ($this->additional_servers->count() === 0) {
                    if (str($value)->contains('(')) {
                        $status = str($value)->before('(')->trim()->value();
                        $health = str($value)->after('(')->before(')')->trim()->value() ?? 'unhealthy';
                    } elseif (str($value)->contains(':')) {
                        $status = str($value)->before(':')->trim()->value();
                        $health = str($value)->after(':')->trim()->value() ?? 'unhealthy';
                    } else {
                        $status = $value;
                        $health = 'unhealthy';
                    }

                    return "$status:$health";
                } else {
                    if (str($value)->contains('(')) {
                        $status = str($value)->before('(')->trim()->value();
                        $health = str($value)->after('(')->before(')')->trim()->value() ?? 'unhealthy';
                    } elseif (str($value)->contains(':')) {
                        $status = str($value)->before(':')->trim()->value();
                        $health = str($value)->after(':')->trim()->value() ?? 'unhealthy';
                    } else {
                        $status = $value;
                        $health = 'unhealthy';
                    }

                    return "$status:$health";
                }
            },
            get: function ($value) {
                if ($this->additional_servers->count() === 0) {
                    //running (healthy)
                    if (str($value)->contains('(')) {
                        $status = str($value)->before('(')->trim()->value();
                        $health = str($value)->after('(')->before(')')->trim()->value() ?? 'unhealthy';
                    } elseif (str($value)->contains(':')) {
                        $status = str($value)->before(':')->trim()->value();
                        $health = str($value)->after(':')->trim()->value() ?? 'unhealthy';
                    } else {
                        $status = $value;
                        $health = 'unhealthy';
                    }

                    return "$status:$health";
                } else {
                    $complex_status = null;
                    $complex_health = null;
                    $complex_status = $main_server_status = str($value)->before(':')->value();
                    $complex_health = $main_server_health = str($value)->after(':')->value() ?? 'unhealthy';
                    $additional_servers_status = $this->additional_servers->pluck('pivot.status');
                    foreach ($additional_servers_status as $status) {
                        $server_status = str($status)->before(':')->value();
                        $server_health = str($status)->after(':')->value() ?? 'unhealthy';
                        if ($main_server_status !== $server_status) {
                            $complex_status = 'degraded';
                        }
                        if ($main_server_health !== $server_health) {
                            $complex_health = 'unhealthy';
                        }
                    }

                    return "$complex_status:$complex_health";
                }
            },
        );
    }

    public function customNginxConfiguration(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => base64_encode($value),
            get: fn ($value) => base64_decode($value),
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

    public function tags()
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    public function project()
    {
        return data_get($this, 'environment.project');
    }

    public function team()
    {
        return data_get($this, 'environment.project.team');
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

    public function main_port()
    {
        return $this->settings->is_static ? [80] : $this->ports_exposes_array;
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

    public function deployment_queue()
    {
        return $this->hasMany(ApplicationDeploymentQueue::class);
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
        $deployments = ApplicationDeploymentQueue::where('application_id', $this->id)->whereIn('status', [ApplicationDeploymentStatus::IN_PROGRESS, ApplicationDeploymentStatus::QUEUED])->count();
        if ($deployments > 0) {
            return true;
        }

        return false;
    }

    public function get_last_successful_deployment()
    {
        return ApplicationDeploymentQueue::where('application_id', $this->id)->where('status', ApplicationDeploymentStatus::FINISHED)->where('pull_request_id', 0)->orderBy('created_at', 'desc')->first();
    }

    public function get_last_days_deployments()
    {
        return ApplicationDeploymentQueue::where('application_id', $this->id)->where('created_at', '>=', now()->subDays(7))->orderBy('created_at', 'desc')->get();
    }

    public function deployments(int $skip = 0, int $take = 10)
    {
        $deployments = ApplicationDeploymentQueue::where('application_id', $this->id)->orderBy('created_at', 'desc');
        $count = $deployments->count();
        $deployments = $deployments->skip($skip)->take($take)->get();

        return [
            'count' => $count,
            'deployments' => $deployments,
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
        } elseif (data_get($this, 'source')) {
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

    public function workdir()
    {
        return application_configuration_dir()."/{$this->uuid}";
    }

    public function isLogDrainEnabled()
    {
        return data_get($this, 'settings.is_log_drain_enabled', false);
    }

    public function isConfigurationChanged(bool $save = false)
    {
        $newConfigHash = base64_encode($this->fqdn.$this->git_repository.$this->git_branch.$this->git_commit_sha.$this->build_pack.$this->static_image.$this->install_command.$this->build_command.$this->start_command.$this->ports_exposes.$this->ports_mappings.$this->base_directory.$this->publish_directory.$this->dockerfile.$this->dockerfile_location.$this->custom_labels.$this->custom_docker_run_options.$this->dockerfile_target_build.$this->redirect.$this->custom_nginx_configuration);
        if ($this->pull_request_id === 0 || $this->pull_request_id === null) {
            $newConfigHash .= json_encode($this->environment_variables()->get('value')->sort());
        } else {
            $newConfigHash .= json_encode($this->environment_variables_preview->get('value')->sort());
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

    public function customRepository()
    {
        return convertGitUrl($this->git_repository, $this->deploymentType(), $this->source);
    }

    public function generateBaseDir(string $uuid)
    {
        return "/artifacts/{$uuid}";
    }

    public function dirOnServer()
    {
        return application_configuration_dir()."/{$this->uuid}";
    }

    public function setGitImportSettings(string $deployment_uuid, string $git_clone_command, bool $public = false)
    {
        $baseDir = $this->generateBaseDir($deployment_uuid);

        if ($this->git_commit_sha !== 'HEAD') {
            $git_clone_command = "{$git_clone_command} && cd {$baseDir} && GIT_SSH_COMMAND=\"ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null\" git -c advice.detachedHead=false checkout {$this->git_commit_sha} >/dev/null 2>&1";
        }
        if ($this->settings->is_git_submodules_enabled) {
            if ($public) {
                $git_clone_command = "{$git_clone_command} && cd {$baseDir} && sed -i \"s#git@\(.*\):#https://\\1/#g\" {$baseDir}/.gitmodules || true";
            }
            $git_clone_command = "{$git_clone_command} && cd {$baseDir} && GIT_SSH_COMMAND=\"ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null\" git submodule update --init --recursive";
        }
        if ($this->settings->is_git_lfs_enabled) {
            $git_clone_command = "{$git_clone_command} && cd {$baseDir} && GIT_SSH_COMMAND=\"ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null\" git lfs pull";
        }

        return $git_clone_command;
    }

    public function getGitRemoteStatus(string $deployment_uuid)
    {
        try {
            ['commands' => $lsRemoteCommand] = $this->generateGitLsRemoteCommands(deployment_uuid: $deployment_uuid, exec_in_docker: false);
            instant_remote_process([$lsRemoteCommand], $this->destination->server, true);

            return [
                'is_accessible' => true,
                'error' => null,
            ];
        } catch (\RuntimeException $ex) {
            return [
                'is_accessible' => false,
                'error' => $ex->getMessage(),
            ];
        }
    }

    public function generateGitLsRemoteCommands(string $deployment_uuid, bool $exec_in_docker = true)
    {
        $branch = $this->git_branch;
        ['repository' => $customRepository, 'port' => $customPort] = $this->customRepository();
        $commands = collect([]);
        $base_command = 'git ls-remote';

        if ($this->deploymentType() === 'source') {
            $source_html_url = data_get($this, 'source.html_url');
            $url = parse_url(filter_var($source_html_url, FILTER_SANITIZE_URL));
            $source_html_url_host = $url['host'];
            $source_html_url_scheme = $url['scheme'];

            if ($this->source->getMorphClass() == 'App\Models\GithubApp') {
                if ($this->source->is_public) {
                    $fullRepoUrl = "{$this->source->html_url}/{$customRepository}";
                    $base_command = "{$base_command} {$this->source->html_url}/{$customRepository}";
                } else {
                    $github_access_token = generate_github_installation_token($this->source);

                    if ($exec_in_docker) {
                        $base_command = "{$base_command} $source_html_url_scheme://x-access-token:$github_access_token@$source_html_url_host/{$customRepository}.git";
                        $fullRepoUrl = "$source_html_url_scheme://x-access-token:$github_access_token@$source_html_url_host/{$customRepository}.git";
                    } else {
                        $base_command = "{$base_command} $source_html_url_scheme://x-access-token:$github_access_token@$source_html_url_host/{$customRepository}";
                        $fullRepoUrl = "$source_html_url_scheme://x-access-token:$github_access_token@$source_html_url_host/{$customRepository}";
                    }
                }

                if ($exec_in_docker) {
                    $commands->push(executeInDocker($deployment_uuid, $base_command));
                } else {
                    $commands->push($base_command);
                }

                return [
                    'commands' => $commands->implode(' && '),
                    'branch' => $branch,
                    'fullRepoUrl' => $fullRepoUrl,
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
            $base_comamnd = "GIT_SSH_COMMAND=\"ssh -o ConnectTimeout=30 -p {$customPort} -o Port={$customPort} -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i /root/.ssh/id_rsa\" {$base_command} {$customRepository}";

            if ($exec_in_docker) {
                $commands = collect([
                    executeInDocker($deployment_uuid, 'mkdir -p /root/.ssh'),
                    executeInDocker($deployment_uuid, "echo '{$private_key}' | base64 -d | tee /root/.ssh/id_rsa > /dev/null"),
                    executeInDocker($deployment_uuid, 'chmod 600 /root/.ssh/id_rsa'),
                ]);
            } else {
                $commands = collect([
                    'mkdir -p /root/.ssh',
                    "echo '{$private_key}' | base64 -d | tee /root/.ssh/id_rsa > /dev/null",
                    'chmod 600 /root/.ssh/id_rsa',
                ]);
            }

            if ($exec_in_docker) {
                $commands->push(executeInDocker($deployment_uuid, $base_comamnd));
            } else {
                $commands->push($base_comamnd);
            }

            return [
                'commands' => $commands->implode(' && '),
                'branch' => $branch,
                'fullRepoUrl' => $fullRepoUrl,
            ];
        }

        if ($this->deploymentType() === 'other') {
            $fullRepoUrl = $customRepository;
            $base_command = "{$base_command} {$customRepository}";
            $base_command = $this->setGitImportSettings($deployment_uuid, $base_command, public: true);

            if ($exec_in_docker) {
                $commands->push(executeInDocker($deployment_uuid, $base_command));
            } else {
                $commands->push($base_command);
            }

            return [
                'commands' => $commands->implode(' && '),
                'branch' => $branch,
                'fullRepoUrl' => $fullRepoUrl,
            ];
        }
    }

    public function generateGitImportCommands(string $deployment_uuid, int $pull_request_id = 0, ?string $git_type = null, bool $exec_in_docker = true, bool $only_checkout = false, ?string $custom_base_dir = null, ?string $commit = null)
    {
        $branch = $this->git_branch;
        ['repository' => $customRepository, 'port' => $customPort] = $this->customRepository();
        $baseDir = $custom_base_dir ?? $this->generateBaseDir($deployment_uuid);
        $commands = collect([]);
        $git_clone_command = "git clone -b \"{$this->git_branch}\"";
        if ($only_checkout) {
            $git_clone_command = "git clone --no-checkout -b \"{$this->git_branch}\"";
        }
        if ($pull_request_id !== 0) {
            $pr_branch_name = "pr-{$pull_request_id}-coolify";
        }
        if ($this->deploymentType() === 'source') {
            $source_html_url = data_get($this, 'source.html_url');
            $url = parse_url(filter_var($source_html_url, FILTER_SANITIZE_URL));
            $source_html_url_host = $url['host'];
            $source_html_url_scheme = $url['scheme'];

            if ($this->source->getMorphClass() === \App\Models\GithubApp::class) {
                if ($this->source->is_public) {
                    $fullRepoUrl = "{$this->source->html_url}/{$customRepository}";
                    $git_clone_command = "{$git_clone_command} {$this->source->html_url}/{$customRepository} {$baseDir}";
                    if (! $only_checkout) {
                        $git_clone_command = $this->setGitImportSettings($deployment_uuid, $git_clone_command, public: true);
                    }
                    if ($exec_in_docker) {
                        $commands->push(executeInDocker($deployment_uuid, $git_clone_command));
                    } else {
                        $commands->push($git_clone_command);
                    }
                } else {
                    $github_access_token = generate_github_installation_token($this->source);
                    if ($exec_in_docker) {
                        $git_clone_command = "{$git_clone_command} $source_html_url_scheme://x-access-token:$github_access_token@$source_html_url_host/{$customRepository}.git {$baseDir}";
                        $fullRepoUrl = "$source_html_url_scheme://x-access-token:$github_access_token@$source_html_url_host/{$customRepository}.git";
                    } else {
                        $git_clone_command = "{$git_clone_command} $source_html_url_scheme://x-access-token:$github_access_token@$source_html_url_host/{$customRepository} {$baseDir}";
                        $fullRepoUrl = "$source_html_url_scheme://x-access-token:$github_access_token@$source_html_url_host/{$customRepository}";
                    }
                    if (! $only_checkout) {
                        $git_clone_command = $this->setGitImportSettings($deployment_uuid, $git_clone_command, public: false);
                    }
                    if ($exec_in_docker) {
                        $commands->push(executeInDocker($deployment_uuid, $git_clone_command));
                    } else {
                        $commands->push($git_clone_command);
                    }
                }
                if ($pull_request_id !== 0) {
                    $branch = "pull/{$pull_request_id}/head:$pr_branch_name";

                    $git_checkout_command = $this->buildGitCheckoutCommand($pr_branch_name);
                    if ($exec_in_docker) {
                        $commands->push(executeInDocker($deployment_uuid, "cd {$baseDir} && git fetch origin {$branch} && $git_checkout_command"));
                    } else {
                        $commands->push("cd {$baseDir} && git fetch origin {$branch} && $git_checkout_command");
                    }
                }

                return [
                    'commands' => $commands->implode(' && '),
                    'branch' => $branch,
                    'fullRepoUrl' => $fullRepoUrl,
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
                    executeInDocker($deployment_uuid, 'mkdir -p /root/.ssh'),
                    executeInDocker($deployment_uuid, "echo '{$private_key}' | base64 -d | tee /root/.ssh/id_rsa > /dev/null"),
                    executeInDocker($deployment_uuid, 'chmod 600 /root/.ssh/id_rsa'),
                ]);
            } else {
                $commands = collect([
                    'mkdir -p /root/.ssh',
                    "echo '{$private_key}' | base64 -d | tee /root/.ssh/id_rsa > /dev/null",
                    'chmod 600 /root/.ssh/id_rsa',
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
                    $git_clone_command = "{$git_clone_command} && cd {$baseDir} && GIT_SSH_COMMAND=\"ssh -o ConnectTimeout=30 -p {$customPort} -o Port={$customPort} -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i /root/.ssh/id_rsa\" git fetch origin $branch && ".$this->buildGitCheckoutCommand($pr_branch_name);
                } elseif ($git_type === 'github' || $git_type === 'gitea') {
                    $branch = "pull/{$pull_request_id}/head:$pr_branch_name";
                    if ($exec_in_docker) {
                        $commands->push(executeInDocker($deployment_uuid, "echo 'Checking out $branch'"));
                    } else {
                        $commands->push("echo 'Checking out $branch'");
                    }
                    $git_clone_command = "{$git_clone_command} && cd {$baseDir} && GIT_SSH_COMMAND=\"ssh -o ConnectTimeout=30 -p {$customPort} -o Port={$customPort} -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i /root/.ssh/id_rsa\" git fetch origin $branch && ".$this->buildGitCheckoutCommand($pr_branch_name);
                } elseif ($git_type === 'bitbucket') {
                    if ($exec_in_docker) {
                        $commands->push(executeInDocker($deployment_uuid, "echo 'Checking out $branch'"));
                    } else {
                        $commands->push("echo 'Checking out $branch'");
                    }
                    $git_clone_command = "{$git_clone_command} && cd {$baseDir} && GIT_SSH_COMMAND=\"ssh -o ConnectTimeout=30 -p {$customPort} -o Port={$customPort} -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i /root/.ssh/id_rsa\" ".$this->buildGitCheckoutCommand($commit);
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
                'fullRepoUrl' => $fullRepoUrl,
            ];
        }
        if ($this->deploymentType() === 'other') {
            $fullRepoUrl = $customRepository;
            $git_clone_command = "{$git_clone_command} {$customRepository} {$baseDir}";
            $git_clone_command = $this->setGitImportSettings($deployment_uuid, $git_clone_command, public: true);

            if ($pull_request_id !== 0) {
                if ($git_type === 'gitlab') {
                    $branch = "merge-requests/{$pull_request_id}/head:$pr_branch_name";
                    if ($exec_in_docker) {
                        $commands->push(executeInDocker($deployment_uuid, "echo 'Checking out $branch'"));
                    } else {
                        $commands->push("echo 'Checking out $branch'");
                    }
                    $git_clone_command = "{$git_clone_command} && cd {$baseDir} && GIT_SSH_COMMAND=\"ssh -o ConnectTimeout=30 -p {$customPort} -o Port={$customPort} -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i /root/.ssh/id_rsa\" git fetch origin $branch && ".$this->buildGitCheckoutCommand($pr_branch_name);
                } elseif ($git_type === 'github' || $git_type === 'gitea') {
                    $branch = "pull/{$pull_request_id}/head:$pr_branch_name";
                    if ($exec_in_docker) {
                        $commands->push(executeInDocker($deployment_uuid, "echo 'Checking out $branch'"));
                    } else {
                        $commands->push("echo 'Checking out $branch'");
                    }
                    $git_clone_command = "{$git_clone_command} && cd {$baseDir} && GIT_SSH_COMMAND=\"ssh -o ConnectTimeout=30 -p {$customPort} -o Port={$customPort} -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i /root/.ssh/id_rsa\" git fetch origin $branch && ".$this->buildGitCheckoutCommand($pr_branch_name);
                } elseif ($git_type === 'bitbucket') {
                    if ($exec_in_docker) {
                        $commands->push(executeInDocker($deployment_uuid, "echo 'Checking out $branch'"));
                    } else {
                        $commands->push("echo 'Checking out $branch'");
                    }
                    $git_clone_command = "{$git_clone_command} && cd {$baseDir} && GIT_SSH_COMMAND=\"ssh -o ConnectTimeout=30 -p {$customPort} -o Port={$customPort} -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i /root/.ssh/id_rsa\" ".$this->buildGitCheckoutCommand($commit);
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
                'fullRepoUrl' => $fullRepoUrl,
            ];
        }
    }

    public function oldRawParser()
    {
        try {
            $yaml = Yaml::parse($this->docker_compose_raw);
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
        $services = data_get($yaml, 'services');

        $commands = collect([]);
        $services = collect($services)->map(function ($service) use ($commands) {
            $serviceVolumes = collect(data_get($service, 'volumes', []));
            if ($serviceVolumes->count() > 0) {
                foreach ($serviceVolumes as $volume) {
                    $workdir = $this->workdir();
                    $type = null;
                    $source = null;
                    if (is_string($volume)) {
                        $source = str($volume)->before(':');
                        if ($source->startsWith('./') || $source->startsWith('/') || $source->startsWith('~')) {
                            $type = str('bind');
                        }
                    } elseif (is_array($volume)) {
                        $type = data_get_str($volume, 'type');
                        $source = data_get_str($volume, 'source');
                    }
                    if ($type?->value() === 'bind') {
                        if ($source->value() === '/var/run/docker.sock') {
                            continue;
                        }
                        if ($source->value() === '/tmp' || $source->value() === '/tmp/') {
                            continue;
                        }
                        if ($source->startsWith('.')) {
                            $source = $source->after('.');
                            $source = $workdir.$source;
                        }
                        $commands->push("mkdir -p $source > /dev/null 2>&1 || true");
                    }
                }
            }
            $labels = collect(data_get($service, 'labels', []));
            if (! $labels->contains('coolify.managed')) {
                $labels->push('coolify.managed=true');
            }
            if (! $labels->contains('coolify.applicationId')) {
                $labels->push('coolify.applicationId='.$this->id);
            }
            if (! $labels->contains('coolify.type')) {
                $labels->push('coolify.type=application');
            }
            data_set($service, 'labels', $labels->toArray());

            return $service;
        });
        data_set($yaml, 'services', $services->toArray());
        $this->docker_compose_raw = Yaml::dump($yaml, 10, 2);

        instant_remote_process($commands, $this->destination->server, false);
    }

    public function parse(int $pull_request_id = 0, ?int $preview_id = null)
    {
        if ((int) $this->compose_parsing_version >= 3) {
            return newParser($this, $pull_request_id, $preview_id);
        } elseif ($this->docker_compose_raw) {
            return parseDockerComposeFile(resource: $this, isNew: false, pull_request_id: $pull_request_id, preview_id: $preview_id);
        } else {
            return collect([]);
        }
    }

    public function loadComposeFile($isInit = false)
    {
        $initialDockerComposeLocation = $this->docker_compose_location;
        if ($isInit && $this->docker_compose_raw) {
            return;
        }
        $uuid = new Cuid2;
        ['commands' => $cloneCommand] = $this->generateGitImportCommands(deployment_uuid: $uuid, only_checkout: true, exec_in_docker: false, custom_base_dir: '.');
        $workdir = rtrim($this->base_directory, '/');
        $composeFile = $this->docker_compose_location;
        $fileList = collect([".$workdir$composeFile"]);
        $gitRemoteStatus = $this->getGitRemoteStatus(deployment_uuid: $uuid);
        if (! $gitRemoteStatus['is_accessible']) {
            throw new \RuntimeException("Failed to read Git source:\n\n{$gitRemoteStatus['error']}");
        }
        $getGitVersion = instant_remote_process(['git --version'], $this->destination->server, false);
        $gitVersion = str($getGitVersion)->explode(' ')->last();

        if (version_compare($gitVersion, '2.35.1', '<')) {
            $fileList = $fileList->map(function ($file) {
                $parts = explode('/', trim($file, '.'));
                $paths = collect();
                $currentPath = '';
                foreach ($parts as $part) {
                    $currentPath .= ($currentPath ? '/' : '').$part;
                    if (str($currentPath)->isNotEmpty()) {
                        $paths->push($currentPath);
                    }
                }

                return $paths;
            })->flatten()->unique()->values();
            $commands = collect([
                "rm -rf /tmp/{$uuid}",
                "mkdir -p /tmp/{$uuid}",
                "cd /tmp/{$uuid}",
                $cloneCommand,
                'git sparse-checkout init',
                "git sparse-checkout set {$fileList->implode(' ')}",
                'git read-tree -mu HEAD',
                "cat .$workdir$composeFile",
            ]);
        } else {
            $commands = collect([
                "rm -rf /tmp/{$uuid}",
                "mkdir -p /tmp/{$uuid}",
                "cd /tmp/{$uuid}",
                $cloneCommand,
                'git sparse-checkout init --cone',
                "git sparse-checkout set {$fileList->implode(' ')}",
                'git read-tree -mu HEAD',
                "cat .$workdir$composeFile",
            ]);
        }
        try {
            $composeFileContent = instant_remote_process($commands, $this->destination->server);
        } catch (\Exception $e) {
            if (str($e->getMessage())->contains('No such file')) {
                throw new \RuntimeException("Docker Compose file not found at: $workdir$composeFile<br><br>Check if you used the right extension (.yaml or .yml) in the compose file name.");
            }
            if (str($e->getMessage())->contains('fatal: repository') && str($e->getMessage())->contains('does not exist')) {
                if ($this->deploymentType() === 'deploy_key') {
                    throw new \RuntimeException('Your deploy key does not have access to the repository. Please check your deploy key and try again.');
                }
                throw new \RuntimeException('Repository does not exist. Please check your repository URL and try again.');
            }
            throw new \RuntimeException($e->getMessage());
        } finally {
            $this->docker_compose_location = $initialDockerComposeLocation;
            $this->save();
            $commands = collect([
                "rm -rf /tmp/{$uuid}",
            ]);
            instant_remote_process($commands, $this->destination->server, false);
        }
        if ($composeFileContent) {
            $this->docker_compose_raw = $composeFileContent;
            $this->save();
            $parsedServices = $this->parse();
            if ($this->docker_compose_domains) {
                $json = collect(json_decode($this->docker_compose_domains));
                $names = collect(data_get($parsedServices, 'services'))->keys()->toArray();
                $jsonNames = $json->keys()->toArray();
                $diff = array_diff($jsonNames, $names);
                $json = $json->filter(function ($value, $key) use ($diff) {
                    return ! in_array($key, $diff);
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
            ];
        } else {
            throw new \RuntimeException("Docker Compose file not found at: $workdir$composeFile<br><br>Check if you used the right extension (.yaml or .yml) in the compose file name.");
        }
    }

    public function parseContainerLabels(?ApplicationPreview $preview = null)
    {
        $customLabels = data_get($this, 'custom_labels');
        if (! $customLabels) {
            return;
        }
        if (base64_encode(base64_decode($customLabels, true)) !== $customLabels) {
            $this->custom_labels = str($customLabels)->replace(',', "\n");
            $this->custom_labels = base64_encode($customLabels);
        }
        $customLabels = base64_decode($this->custom_labels);
        if (mb_detect_encoding($customLabels, 'ASCII', true) === false) {
            $customLabels = str(implode('|coolify|', generateLabelsApplication($this, $preview)))->replace('|coolify|', "\n");
        }
        $this->custom_labels = base64_encode($customLabels);
        $this->save();

        return $customLabels;
    }

    public function fqdns(): Attribute
    {
        return Attribute::make(
            get: fn () => is_null($this->fqdn)
                ? []
                : explode(',', $this->fqdn),
        );
    }

    protected function buildGitCheckoutCommand($target): string
    {
        $command = "git checkout $target";

        if ($this->settings->is_git_submodules_enabled) {
            $command .= ' && git submodule update --init --recursive';
        }

        return $command;
    }

    public function watchPaths(): Attribute
    {
        return Attribute::make(
            set: function ($value) {
                if ($value) {
                    return trim($value);
                }
            }
        );
    }

    public function isWatchPathsTriggered(Collection $modified_files): bool
    {
        if (is_null($this->watch_paths)) {
            return false;
        }
        $watch_paths = collect(explode("\n", $this->watch_paths));
        $matches = $modified_files->filter(function ($file) use ($watch_paths) {
            return $watch_paths->contains(function ($glob) use ($file) {
                return fnmatch($glob, $file);
            });
        });

        return $matches->count() > 0;
    }

    public function getFilesFromServer(bool $isInit = false)
    {
        getFilesystemVolumesFromServer($this, $isInit);
    }

    public function parseHealthcheckFromDockerfile($dockerfile, bool $isInit = false)
    {
        if (str($dockerfile)->contains('HEALTHCHECK') && ($this->isHealthcheckDisabled() || $isInit)) {
            $healthcheckCommand = null;
            $lines = $dockerfile->toArray();
            foreach ($lines as $line) {
                $trimmedLine = trim($line);
                if (str_starts_with($trimmedLine, 'HEALTHCHECK')) {
                    $healthcheckCommand .= trim($trimmedLine, '\\ ');

                    continue;
                }
                if (isset($healthcheckCommand) && str_contains($trimmedLine, '\\')) {
                    $healthcheckCommand .= ' '.trim($trimmedLine, '\\ ');
                }
                if (isset($healthcheckCommand) && ! str_contains($trimmedLine, '\\') && ! empty($healthcheckCommand)) {
                    $healthcheckCommand .= ' '.$trimmedLine;
                    break;
                }
            }
            if (str($healthcheckCommand)->isNotEmpty()) {
                $interval = str($healthcheckCommand)->match('/--interval=(\d+)/');
                $timeout = str($healthcheckCommand)->match('/--timeout=(\d+)/');
                $start_period = str($healthcheckCommand)->match('/--start-period=(\d+)/');
                $start_interval = str($healthcheckCommand)->match('/--start-interval=(\d+)/');
                $retries = str($healthcheckCommand)->match('/--retries=(\d+)/');
                if ($interval->isNotEmpty()) {
                    $this->health_check_interval = $interval->toInteger();
                }
                if ($timeout->isNotEmpty()) {
                    $this->health_check_timeout = $timeout->toInteger();
                }
                if ($start_period->isNotEmpty()) {
                    $this->health_check_start_period = $start_period->toInteger();
                }
                // if ($start_interval) {
                //     $this->health_check_start_interval = $start_interval->value();
                // }
                if ($retries->isNotEmpty()) {
                    $this->health_check_retries = $retries->toInteger();
                }
                if ($interval || $timeout || $start_period || $start_interval || $retries) {
                    $this->custom_healthcheck_found = true;
                    $this->save();
                }
            }
        }
    }

    public function generate_preview_fqdn(int $pull_request_id)
    {
        $preview = ApplicationPreview::findPreviewByApplicationAndPullId($this->id, $pull_request_id);
        if (is_null(data_get($preview, 'fqdn')) && $this->fqdn) {
            if (str($this->fqdn)->contains(',')) {
                $url = Url::fromString(str($this->fqdn)->explode(',')[0]);
                $preview_fqdn = getFqdnWithoutPort(str($this->fqdn)->explode(',')[0]);
            } else {
                $url = Url::fromString($this->fqdn);
                if (data_get($preview, 'fqdn')) {
                    $preview_fqdn = getFqdnWithoutPort(data_get($preview, 'fqdn'));
                }
            }
            $template = $this->preview_url_template;
            $host = $url->getHost();
            $schema = $url->getScheme();
            $random = new Cuid2;
            $preview_fqdn = str_replace('{{random}}', $random, $template);
            $preview_fqdn = str_replace('{{domain}}', $host, $preview_fqdn);
            $preview_fqdn = str_replace('{{pr_id}}', $pull_request_id, $preview_fqdn);
            $preview_fqdn = "$schema://$preview_fqdn";
            $preview->fqdn = $preview_fqdn;
            $preview->save();
        }

        return $preview;
    }

    public static function getDomainsByUuid(string $uuid): array
    {
        $application = self::where('uuid', $uuid)->first();

        if ($application) {
            return $application->fqdns;
        }

        return [];
    }

    public function getCpuMetrics(int $mins = 5)
    {
        $server = $this->destination->server;
        $container_name = $this->uuid;
        if ($server->isMetricsEnabled()) {
            $from = now()->subMinutes($mins)->toIso8601ZuluString();
            $metrics = instant_remote_process(["docker exec coolify-sentinel sh -c 'curl -H \"Authorization: Bearer {$server->settings->sentinel_token}\" http://localhost:8888/api/container/{$container_name}/cpu/history?from=$from'"], $server, false);
            if (str($metrics)->contains('error')) {
                $error = json_decode($metrics, true);
                $error = data_get($error, 'error', 'Something is not okay, are you okay?');
                if ($error === 'Unauthorized') {
                    $error = 'Unauthorized, please check your metrics token or restart Sentinel to set a new token.';
                }
                throw new \Exception($error);
            }
            $metrics = json_decode($metrics, true);
            $parsedCollection = collect($metrics)->map(function ($metric) {
                return [(int) $metric['time'], (float) $metric['percent']];
            });

            return $parsedCollection->toArray();
        }
    }

    public function getMemoryMetrics(int $mins = 5)
    {
        $server = $this->destination->server;
        $container_name = $this->uuid;
        if ($server->isMetricsEnabled()) {
            $from = now()->subMinutes($mins)->toIso8601ZuluString();
            $metrics = instant_remote_process(["docker exec coolify-sentinel sh -c 'curl -H \"Authorization: Bearer {$server->settings->sentinel_token}\" http://localhost:8888/api/container/{$container_name}/memory/history?from=$from'"], $server, false);
            if (str($metrics)->contains('error')) {
                $error = json_decode($metrics, true);
                $error = data_get($error, 'error', 'Something is not okay, are you okay?');
                if ($error === 'Unauthorized') {
                    $error = 'Unauthorized, please check your metrics token or restart Sentinel to set a new token.';
                }
                throw new \Exception($error);
            }
            $metrics = json_decode($metrics, true);
            $parsedCollection = collect($metrics)->map(function ($metric) {
                return [(int) $metric['time'], (float) $metric['used']];
            });

            return $parsedCollection->toArray();
        }
    }

    public function generateConfig($is_json = false)
    {
        $config = collect([]);
        if ($this->build_pack = 'nixpacks') {
            $config = collect([
                'build_pack' => 'nixpacks',
                'docker_registry_image_name' => $this->docker_registry_image_name,
                'docker_registry_image_tag' => $this->docker_registry_image_tag,
                'install_command' => $this->install_command,
                'build_command' => $this->build_command,
                'start_command' => $this->start_command,
                'base_directory' => $this->base_directory,
                'publish_directory' => $this->publish_directory,
                'custom_docker_run_options' => $this->custom_docker_run_options,
                'ports_exposes' => $this->ports_exposes,
                'ports_mappings' => $this->ports_mapping,
                'settings' => collect([
                    'is_static' => $this->settings->is_static,
                ]),
            ]);
        }
        $config = $config->filter(function ($value) {
            return str($value)->isNotEmpty();
        });
        if ($is_json) {
            return json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        return $config;
    }

    public function setConfig($config)
    {
        $validator = Validator::make(['config' => $config], [
            'config' => 'required|json',
        ]);
        if ($validator->fails()) {
            throw new \Exception('Invalid JSON format');
        }
        $config = json_decode($config, true);

        $deepValidator = Validator::make(['config' => $config], [
            'config.build_pack' => 'required|string',
            'config.base_directory' => 'required|string',
            'config.publish_directory' => 'required|string',
            'config.ports_exposes' => 'required|string',
            'config.settings.is_static' => 'required|boolean',
        ]);
        if ($deepValidator->fails()) {
            throw new \Exception('Invalid data');
        }
        $config = $deepValidator->validated()['config'];

        try {
            $settings = data_get($config, 'settings', []);
            data_forget($config, 'settings');
            $this->update($config);
            $this->settings()->update($settings);
        } catch (\Exception $e) {
            throw new \Exception('Failed to update application settings');
        }
    }
}
