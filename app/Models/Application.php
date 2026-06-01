<?php

namespace App\Models;

use App\Enums\ApplicationDeploymentStatus;
use App\Services\ConfigurationGenerator;
use App\Services\DeploymentConfiguration\ApplicationConfigurationSnapshot;
use App\Services\DeploymentConfiguration\ConfigurationDiff;
use App\Services\DeploymentConfiguration\ConfigurationDiffer;
use App\Traits\ClearsGlobalSearchCache;
use App\Traits\HasConfiguration;
use App\Traits\HasMetrics;
use App\Traits\HasSafeStringAttribute;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
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
        'build_pack' => ['type' => 'string', 'description' => 'Build pack.', 'enum' => ['nixpacks', 'railpack', 'static', 'dockerfile', 'dockercompose']],
        'static_image' => ['type' => 'string', 'description' => 'Static image used when static site is deployed.'],
        'install_command' => ['type' => 'string', 'description' => 'Install command.'],
        'build_command' => ['type' => 'string', 'description' => 'Build command.'],
        'start_command' => ['type' => 'string', 'description' => 'Start command.'],
        'ports_exposes' => ['type' => 'string', 'description' => 'Ports exposes.'],
        'ports_mappings' => ['type' => 'string', 'nullable' => true, 'description' => 'Ports mappings.'],
        'custom_network_aliases' => ['type' => 'string', 'nullable' => true, 'description' => 'Network aliases for Docker container.'],
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
        'health_check_type' => ['type' => 'string', 'description' => 'Health check type: http or cmd.', 'enum' => ['http', 'cmd']],
        'health_check_command' => ['type' => 'string', 'nullable' => true, 'description' => 'Health check command for CMD type.'],
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
        'is_http_basic_auth_enabled' => ['type' => 'boolean', 'description' => 'HTTP Basic Authentication enabled.'],
        'http_basic_auth_username' => ['type' => 'string', 'nullable' => true, 'description' => 'Username for HTTP Basic Authentication'],
        'http_basic_auth_password' => ['type' => 'string', 'nullable' => true, 'description' => 'Password for HTTP Basic Authentication'],
    ]
)]

class Application extends BaseModel
{
    use ClearsGlobalSearchCache, HasConfiguration, HasFactory, HasMetrics, HasSafeStringAttribute, SoftDeletes;

    private static $parserVersion = '5';

    protected $fillable = [
        'name',
        'description',
        'fqdn',
        'git_repository',
        'git_branch',
        'git_commit_sha',
        'git_full_url',
        'docker_registry_image_name',
        'docker_registry_image_tag',
        'build_pack',
        'static_image',
        'install_command',
        'build_command',
        'start_command',
        'ports_exposes',
        'ports_mappings',
        'base_directory',
        'publish_directory',
        'health_check_enabled',
        'health_check_path',
        'health_check_port',
        'health_check_host',
        'health_check_method',
        'health_check_return_code',
        'health_check_scheme',
        'health_check_response_text',
        'health_check_interval',
        'health_check_timeout',
        'health_check_retries',
        'health_check_start_period',
        'health_check_type',
        'health_check_command',
        'limits_memory',
        'limits_memory_swap',
        'limits_memory_swappiness',
        'limits_memory_reservation',
        'limits_cpus',
        'limits_cpuset',
        'limits_cpu_shares',
        'status',
        'preview_url_template',
        'dockerfile',
        'dockerfile_location',
        'dockerfile_target_build',
        'custom_labels',
        'custom_docker_run_options',
        'post_deployment_command',
        'post_deployment_command_container',
        'pre_deployment_command',
        'pre_deployment_command_container',
        'manual_webhook_secret_github',
        'manual_webhook_secret_gitlab',
        'manual_webhook_secret_bitbucket',
        'manual_webhook_secret_gitea',
        'docker_compose_location',
        'docker_compose_pr_location',
        'docker_compose',
        'docker_compose_pr',
        'docker_compose_raw',
        'docker_compose_pr_raw',
        'docker_compose_domains',
        'docker_compose_custom_start_command',
        'docker_compose_custom_build_command',
        'swarm_replicas',
        'swarm_placement_constraints',
        'watch_paths',
        'redirect',
        'compose_parsing_version',
        'custom_nginx_configuration',
        'custom_network_aliases',
        'custom_healthcheck_found',
        'nixpkgsarchive',
        'is_http_basic_auth_enabled',
        'http_basic_auth_username',
        'http_basic_auth_password',
        'connect_to_docker_network',
        'force_domain_override',
        'is_container_label_escape_enabled',
        'use_build_server',
        'config_hash',
        'last_online_at',
        'restart_count',
        'last_restart_at',
        'last_restart_type',
        'uuid',
        'environment_id',
        'destination_id',
        'destination_type',
        'source_id',
        'source_type',
        'repository_project_id',
        'private_key_id',
    ];

    protected $appends = ['server_status'];

    protected function casts(): array
    {
        return [
            'http_basic_auth_password' => 'encrypted',
            'manual_webhook_secret_github' => 'encrypted',
            'manual_webhook_secret_gitlab' => 'encrypted',
            'manual_webhook_secret_bitbucket' => 'encrypted',
            'manual_webhook_secret_gitea' => 'encrypted',
            'restart_count' => 'integer',
            'last_restart_at' => 'datetime',
        ];
    }

    protected static function booted()
    {
        static::creating(function ($application) {
            $application->manual_webhook_secret_github ??= Str::random(40);
            $application->manual_webhook_secret_gitlab ??= Str::random(40);
            $application->manual_webhook_secret_bitbucket ??= Str::random(40);
            $application->manual_webhook_secret_gitea ??= Str::random(40);
        });
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
            if ($application->isDirty('git_repository')) {
                $payload['git_repository'] = str($application->git_repository)->trim();
            }
            if ($application->isDirty('git_branch')) {
                $payload['git_branch'] = str($application->git_branch)->trim();
            }
            if ($application->isDirty('git_commit_sha')) {
                $payload['git_commit_sha'] = str($application->git_commit_sha)->trim();
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
                $application->fill($payload);
            }

            // Buildpack switching cleanup logic
            if ($application->isDirty('build_pack')) {
                $originalBuildPack = $application->getOriginal('build_pack');

                // Clear Docker Compose specific data when switching away from dockercompose
                if ($originalBuildPack === 'dockercompose') {
                    $application->docker_compose_domains = null;
                    $application->docker_compose_raw = null;

                    // Remove SERVICE_FQDN_* and SERVICE_URL_* environment variables
                    $application->environment_variables()
                        ->where(function ($q) {
                            $q->where('key', 'LIKE', 'SERVICE_FQDN_%')
                                ->orWhere('key', 'LIKE', 'SERVICE_URL_%');
                        })
                        ->delete();
                    $application->environment_variables_preview()
                        ->where(function ($q) {
                            $q->where('key', 'LIKE', 'SERVICE_FQDN_%')
                                ->orWhere('key', 'LIKE', 'SERVICE_URL_%');
                        })
                        ->delete();
                }

                // Clear Dockerfile specific data when switching away from dockerfile
                if ($originalBuildPack === 'dockerfile') {
                    $application->dockerfile = null;
                    $application->dockerfile_location = null;
                    $application->dockerfile_target_build = null;
                    $application->custom_healthcheck_found = false;
                }
            }
        });
        static::created(function ($application) {
            ApplicationSetting::create([
                'application_id' => $application->id,
            ]);
            $application->compose_parsing_version = self::$parserVersion;
            $application->save();

            // Add default NIXPACKS_NODE_VERSION environment variable for Nixpacks applications
            if ($application->build_pack === 'nixpacks') {
                EnvironmentVariable::create([
                    'key' => 'NIXPACKS_NODE_VERSION',
                    'value' => '22',
                    'is_multiline' => false,
                    'is_literal' => false,
                    'is_buildtime' => true,
                    'is_runtime' => false,
                    'is_preview' => false,
                    'resourceable_type' => Application::class,
                    'resourceable_id' => $application->id,
                ]);
            }
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

    public function customNetworkAliases(): Attribute
    {
        return Attribute::make(
            set: function ($value) {
                if (is_null($value) || $value === '') {
                    return null;
                }

                // If it's already a JSON string, decode it
                if (is_string($value) && $this->isJson($value)) {
                    $value = json_decode($value, true);
                }

                // If it's a string but not JSON, treat it as a comma-separated list
                if (is_string($value) && ! is_array($value)) {
                    $value = explode(',', $value);
                }

                $value = collect($value)
                    ->map(function ($alias) {
                        if (is_string($alias)) {
                            return str_replace(' ', '-', trim($alias));
                        }

                        return null;
                    })
                    ->filter()
                    ->unique() // Remove duplicate values
                    ->values()
                    ->toArray();

                return empty($value) ? null : json_encode($value);
            },
            get: function ($value) {
                if (is_null($value)) {
                    return null;
                }

                if (is_string($value) && $this->isJson($value)) {
                    $decoded = json_decode($value, true);

                    // Return as comma-separated string, not array
                    return is_array($decoded) ? implode(',', $decoded) : $value;
                }

                return $value;
            }
        );
    }

    /**
     * Get custom_network_aliases as an array
     */
    public function customNetworkAliasesArray(): Attribute
    {
        return Attribute::make(
            get: function () {
                $value = $this->getRawOriginal('custom_network_aliases');
                if (is_null($value)) {
                    return null;
                }

                if (is_string($value) && $this->isJson($value)) {
                    return json_decode($value, true);
                }

                return is_array($value) ? $value : [];
            }
        );
    }

    /**
     * Check if a string is a valid JSON
     */
    private function isJson($string)
    {
        if (! is_string($string)) {
            return false;
        }
        json_decode($string);

        return json_last_error() === JSON_ERROR_NONE;
    }

    public static function ownedByCurrentTeamAPI(int $teamId)
    {
        return Application::whereRelation('environment.project.team', 'id', $teamId)->orderBy('name');
    }

    /**
     * Get query builder for applications owned by current team.
     * If you need all applications without further query chaining, use ownedByCurrentTeamCached() instead.
     */
    public static function ownedByCurrentTeam()
    {
        return Application::whereRelation('environment.project.team', 'id', currentTeam()->id)->orderBy('name');
    }

    /**
     * Get all applications owned by current team (cached for request duration).
     */
    public static function ownedByCurrentTeamCached()
    {
        return once(function () {
            return Application::ownedByCurrentTeam()->get();
        });
    }

    public function getContainersToStop(Server $server, bool $previewDeployments = false): array
    {
        $containers = $previewDeployments
            ? getCurrentApplicationContainerStatus($server, $this->id, includePullrequests: true)
            : getCurrentApplicationContainerStatus($server, $this->id, 0);

        return $containers->pluck('Names')->toArray();
    }

    public function deleteConfigurations()
    {
        $server = data_get($this, 'destination.server');
        $workdir = $this->workdir();
        if (str($workdir)->endsWith($this->uuid)) {
            instant_remote_process(['rm -rf '.$this->workdir()], $server, false);
        }
    }

    public function deleteVolumes()
    {
        $persistentStorages = $this->persistentStorages()->get() ?? collect();
        if ($this->build_pack === 'dockercompose') {
            $server = data_get($this, 'destination.server');
            instant_remote_process(["cd {$this->dirOnServer()} && docker compose down -v"], $server, false);
        } else {
            if ($persistentStorages->count() === 0) {
                return;
            }
            $server = data_get($this, 'destination.server');
            foreach ($persistentStorages as $storage) {
                instant_remote_process(['docker volume rm -f '.escapeshellarg($storage->name)], $server, false);
            }
        }
    }

    public function deleteConnectedNetworks()
    {
        $uuid = $this->uuid;
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
                'environment_uuid' => data_get($this, 'environment.uuid'),
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
                'environment_uuid' => data_get($this, 'environment.uuid'),
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
                $base_dir = $this->base_directory ?? '/';
                if (! is_null($this->source?->html_url) && ! is_null($this->git_repository) && ! is_null($this->git_branch)) {
                    if (str($this->git_repository)->contains('bitbucket')) {
                        return "{$this->source->html_url}/{$this->git_repository}/src/{$this->git_branch}{$base_dir}";
                    }

                    return "{$this->source->html_url}/{$this->git_repository}/tree/{$this->git_branch}{$base_dir}";
                }
                // Convert the SSH URL to HTTPS URL
                if (strpos($this->git_repository, 'git@') === 0) {
                    $git_repository = str_replace(['git@', ':', '.git'], ['', '/', ''], $this->git_repository);

                    if (str($this->git_repository)->contains('bitbucket')) {
                        return "https://{$git_repository}/src/{$this->git_branch}{$base_dir}";
                    }

                    return "https://{$git_repository}/tree/{$this->git_branch}{$base_dir}";
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
                    return $this->build_pack === 'dockerfile' ? '/Dockerfile' : null;
                }

                if ($value !== '/') {
                    return Str::start(Str::replaceEnd('/', '', $value), '/');
                }

                return Str::start($value, '/');
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
                // Check main server infrastructure health
                $main_server_functional = $this->destination?->server?->isFunctional() ?? false;

                if (! $main_server_functional) {
                    return false;
                }

                // Check additional servers infrastructure health (not container status!)
                if ($this->relationLoaded('additional_servers') && $this->additional_servers->count() > 0) {
                    foreach ($this->additional_servers as $server) {
                        if (! $server->isFunctional()) {
                            return false;  // Real server infrastructure problem
                        }
                    }
                }

                return true;
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
                    // running (healthy)
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
            set: fn ($value) => is_null($value) ? null : base64_encode($value),
            get: fn ($value) => is_null($value) ? null : base64_decode($value),
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

    public function detectPortFromEnvironment(?bool $isPreview = false): ?int
    {
        $envVars = $isPreview
            ? $this->environment_variables_preview
            : $this->environment_variables;

        $portVar = $envVars->firstWhere('key', 'PORT');

        if ($portVar && $portVar->real_value) {
            $portValue = trim($portVar->real_value);
            if (is_numeric($portValue)) {
                return (int) $portValue;
            }
        }

        return null;
    }

    public function environment_variables()
    {
        return $this->morphMany(EnvironmentVariable::class, 'resourceable')
            ->where('is_preview', false);
    }

    public function runtime_environment_variables()
    {
        return $this->morphMany(EnvironmentVariable::class, 'resourceable')
            ->where('is_preview', false)
            ->withoutBuildpackControlVariables();
    }

    public function nixpacks_environment_variables()
    {
        return $this->morphMany(EnvironmentVariable::class, 'resourceable')
            ->where('is_preview', false)
            ->where('key', 'like', 'NIXPACKS_%');
    }

    public function railpack_environment_variables()
    {
        return $this->morphMany(EnvironmentVariable::class, 'resourceable')
            ->where('is_preview', false)
            ->where('key', 'like', 'RAILPACK_%');
    }

    public function environment_variables_preview()
    {
        return $this->morphMany(EnvironmentVariable::class, 'resourceable')
            ->where('is_preview', true)
            ->orderByRaw("
                CASE
                    WHEN is_required = true THEN 1
                    WHEN LOWER(key) LIKE 'service_%' THEN 2
                    ELSE 3
                END,
                LOWER(key) ASC
            ");
    }

    public function runtime_environment_variables_preview()
    {
        return $this->morphMany(EnvironmentVariable::class, 'resourceable')
            ->where('is_preview', true)
            ->withoutBuildpackControlVariables();
    }

    public function nixpacks_environment_variables_preview()
    {
        return $this->morphMany(EnvironmentVariable::class, 'resourceable')
            ->where('is_preview', true)
            ->where('key', 'like', 'NIXPACKS_%');
    }

    public function railpack_environment_variables_preview()
    {
        return $this->morphMany(EnvironmentVariable::class, 'resourceable')
            ->where('is_preview', true)
            ->where('key', 'like', 'RAILPACK_%');
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
        return $this->hasMany(ApplicationPreview::class)->orderBy('pull_request_id', 'desc');
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
        return ApplicationDeploymentQueue::where('application_id', $this->id)->where('status', ApplicationDeploymentStatus::FINISHED->value)->where('pull_request_id', 0)->orderBy('created_at', 'desc')->first();
    }

    public function get_last_days_deployments()
    {
        return ApplicationDeploymentQueue::where('application_id', $this->id)->where('created_at', '>=', now()->subDays(7))->orderBy('created_at', 'desc')->get();
    }

    public function deployments(int $skip = 0, int $take = 10, ?string $pullRequestId = null)
    {
        $deployments = ApplicationDeploymentQueue::where('application_id', $this->id)->orderBy('created_at', 'desc');

        if ($pullRequestId) {
            $deployments = $deployments->where('pull_request_id', $pullRequestId);
        }

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
        $privateKeyId = data_get($this, 'private_key_id');

        // Real private key (id > 0) always takes precedence
        if ($privateKeyId !== null && $privateKeyId > 0) {
            return 'deploy_key';
        }

        // GitHub/GitLab App source
        if (data_get($this, 'source')) {
            return 'source';
        }

        // Localhost key (id = 0) when no source is configured
        if ($privateKeyId === 0) {
            return 'deploy_key';
        }

        return 'other';
    }

    public function could_set_build_commands(): bool
    {
        if ($this->build_pack === 'nixpacks' || $this->build_pack === 'railpack') {
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
        $configurationDiff = $this->pendingDeploymentConfigurationDiff();

        if ($save) {
            $this->markDeploymentConfigurationApplied();
        }

        return $configurationDiff->isChanged();
    }

    public function pendingDeploymentConfigurationDiff(): ConfigurationDiff
    {
        $currentSnapshot = $this->deploymentConfigurationSnapshot();
        $lastDeployment = $this->get_last_successful_deployment();

        $previousSnapshot = $lastDeployment?->configuration_snapshot;

        if (! $previousSnapshot) {
            $oldConfigHash = data_get($this, 'config_hash');
            $hasLegacyChange = $oldConfigHash === null || $oldConfigHash !== $this->legacyConfigurationHash();

            if (! $hasLegacyChange) {
                return ConfigurationDiff::unchanged();
            }

            $previousSnapshot = [];
        }

        return app(ConfigurationDiffer::class)->diff($previousSnapshot, $currentSnapshot);
    }

    public function hasPendingDeploymentConfigurationChanges(): bool
    {
        return $this->pendingDeploymentConfigurationDiff()->isChanged();
    }

    public function deploymentConfigurationSnapshot(): array
    {
        return (new ApplicationConfigurationSnapshot($this))->toArray();
    }

    public function deploymentConfigurationHash(): string
    {
        return ApplicationConfigurationSnapshot::hashSnapshot($this->deploymentConfigurationSnapshot());
    }

    public function markDeploymentConfigurationApplied(?ApplicationDeploymentQueue $deployment = null): void
    {
        $this->refresh();

        if (! $deployment) {
            $this->forceFill(['config_hash' => $this->legacyConfigurationHash()])->save();

            return;
        }

        $snapshot = $this->deploymentConfigurationSnapshot();
        $hash = ApplicationConfigurationSnapshot::hashSnapshot($snapshot);

        $previousDeployment = ApplicationDeploymentQueue::query()
            ->where('application_id', $this->id)
            ->where('status', ApplicationDeploymentStatus::FINISHED->value)
            ->where('pull_request_id', $deployment->pull_request_id ?? 0)
            ->where('id', '!=', $deployment->id)
            ->whereNotNull('configuration_snapshot')
            ->latest()
            ->first();

        $deployment->update([
            'configuration_hash' => $hash,
            'configuration_snapshot' => $snapshot,
            'configuration_diff' => $previousDeployment?->configuration_snapshot
                ? app(ConfigurationDiffer::class)->diff($previousDeployment->configuration_snapshot, $snapshot)->toArray()
                : null,
        ]);

        $this->forceFill(['config_hash' => $hash])->save();
    }

    private function legacyConfigurationHash(): string
    {
        $newConfigHash = base64_encode($this->fqdn.$this->git_repository.$this->git_branch.$this->git_commit_sha.$this->build_pack.$this->static_image.$this->install_command.$this->build_command.$this->start_command.$this->ports_exposes.$this->ports_mappings.$this->custom_network_aliases.$this->base_directory.$this->publish_directory.$this->dockerfile.$this->dockerfile_location.$this->custom_labels.$this->custom_docker_run_options.$this->dockerfile_target_build.$this->redirect.$this->custom_nginx_configuration.$this->settings?->use_build_secrets.$this->settings?->inject_build_args_to_dockerfile.$this->settings?->include_source_commit_in_build);
        if ($this->pull_request_id === 0 || $this->pull_request_id === null) {
            $newConfigHash .= json_encode($this->environment_variables()->get(['value',  'is_multiline', 'is_literal', 'is_buildtime', 'is_runtime'])->sort());
        } else {
            $newConfigHash .= json_encode($this->environment_variables_preview()->get(['value', 'is_multiline', 'is_literal', 'is_buildtime', 'is_runtime'])->sort());
        }

        return md5($newConfigHash);
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

    public function setGitImportSettings(string $deployment_uuid, string $git_clone_command, bool $public = false, ?string $commit = null, ?string $git_ssh_command = null)
    {
        $baseDir = $this->generateBaseDir($deployment_uuid);
        $escapedBaseDir = escapeshellarg($baseDir);
        $isShallowCloneEnabled = $this->settings?->is_git_shallow_clone_enabled ?? false;

        // Use the full GIT_SSH_COMMAND (including -i for SSH key and port options) when provided,
        // so that git fetch, submodule update, and lfs pull can authenticate the same way as git clone.
        $sshCommand = $git_ssh_command ?? 'GIT_SSH_COMMAND="ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null"';

        // Use the explicitly passed commit (e.g. from rollback), falling back to the application's git_commit_sha.
        // Invalid refs will cause the git checkout/fetch command to fail on the remote server.
        $commitToUse = $commit ?? $this->git_commit_sha;

        if ($commitToUse !== 'HEAD') {
            $escapedCommit = escapeshellarg($commitToUse);
            // If shallow clone is enabled and we need a specific commit,
            // we need to fetch that specific commit with depth=1
            if ($isShallowCloneEnabled) {
                $git_clone_command = "{$git_clone_command} && cd {$escapedBaseDir} && {$sshCommand} git fetch --depth=1 origin {$escapedCommit} && git -c advice.detachedHead=false checkout {$escapedCommit} >/dev/null 2>&1";
            } else {
                $git_clone_command = "{$git_clone_command} && cd {$escapedBaseDir} && {$sshCommand} git -c advice.detachedHead=false checkout {$escapedCommit} >/dev/null 2>&1";
            }
        }
        if ($this->settings->is_git_submodules_enabled) {
            // Check if .gitmodules file exists before running submodule commands
            $git_clone_command = "{$git_clone_command} && cd {$escapedBaseDir} && if [ -f .gitmodules ]; then";
            if ($public) {
                $git_clone_command = "{$git_clone_command} sed -i \"s#git@\(.*\):#https://\\1/#g\" {$escapedBaseDir}/.gitmodules || true &&";
            }
            // Add shallow submodules flag if shallow clone is enabled
            $submoduleFlags = $isShallowCloneEnabled ? '--depth=1' : '';
            $git_clone_command = "{$git_clone_command} git submodule sync && {$sshCommand} git submodule update --init --recursive {$submoduleFlags}; fi";
        }
        if ($this->settings->is_git_lfs_enabled) {
            $git_clone_command = "{$git_clone_command} && cd {$escapedBaseDir} && {$sshCommand} git lfs pull";
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
        } catch (RuntimeException $ex) {
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
                $escapedCustomRepository = escapeshellarg($customRepository);
                if ($this->source->is_public) {
                    $escapedRepoUrl = escapeshellarg("{$this->source->html_url}/{$customRepository}");
                    $fullRepoUrl = "{$this->source->html_url}/{$customRepository}";
                    $base_command = "{$base_command} {$escapedRepoUrl}";
                } else {
                    $github_access_token = generateGithubInstallationToken($this->source);
                    $encodedToken = rawurlencode($github_access_token);

                    if ($exec_in_docker) {
                        $repoUrl = "$source_html_url_scheme://x-access-token:$encodedToken@$source_html_url_host/{$customRepository}.git";
                        $escapedRepoUrl = escapeshellarg($repoUrl);
                        $base_command = "{$base_command} {$escapedRepoUrl}";
                        $fullRepoUrl = $repoUrl;
                    } else {
                        $repoUrl = "$source_html_url_scheme://x-access-token:$encodedToken@$source_html_url_host/{$customRepository}";
                        $escapedRepoUrl = escapeshellarg($repoUrl);
                        $base_command = "{$base_command} {$escapedRepoUrl}";
                        $fullRepoUrl = $repoUrl;
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

            if ($this->source->getMorphClass() === GitlabApp::class) {
                $gitlabSource = $this->source;
                $private_key = data_get($gitlabSource, 'privateKey.private_key');

                if ($private_key) {
                    $fullRepoUrl = $customRepository;
                    $private_key = base64_encode($private_key);
                    $gitlabPort = $gitlabSource->custom_port ?? 22;
                    $escapedCustomRepository = str_replace("'", "'\\''", $customRepository);
                    $base_command = "GIT_SSH_COMMAND=\"ssh -o ConnectTimeout=30 -p {$gitlabPort} -o Port={$gitlabPort} -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i /root/.ssh/id_rsa\" {$base_command} '{$escapedCustomRepository}'";

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

                // GitLab source without private key — use URL as-is (supports user-embedded basic auth)
                $fullRepoUrl = $customRepository;
                $escapedCustomRepository = escapeshellarg($customRepository);
                $base_command = "{$base_command} {$escapedCustomRepository}";

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
            // When used with executeInDocker (which uses bash -c '...'), we need to escape for bash context
            // Replace ' with '\'' to safely escape within single-quoted bash strings
            $escapedCustomRepository = str_replace("'", "'\\''", $customRepository);
            $base_command = "GIT_SSH_COMMAND=\"ssh -o ConnectTimeout=30 -p {$customPort} -o Port={$customPort} -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i /root/.ssh/id_rsa\" {$base_command} '{$escapedCustomRepository}'";

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

        if ($this->deploymentType() === 'other') {
            $fullRepoUrl = $customRepository;
            $escapedCustomRepository = escapeshellarg($customRepository);
            $base_command = "{$base_command} {$escapedCustomRepository}";

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

        // Escape shell arguments for safety to prevent command injection
        $escapedBranch = escapeshellarg($branch);
        $escapedBaseDir = escapeshellarg($baseDir);

        $commands = collect([]);

        // Check if shallow clone is enabled
        $isShallowCloneEnabled = $this->settings?->is_git_shallow_clone_enabled ?? false;
        $depthFlag = $isShallowCloneEnabled ? ' --depth=1' : '';

        $submoduleFlags = '';
        if ($this->settings->is_git_submodules_enabled) {
            $submoduleFlags = ' --recurse-submodules';
            if ($isShallowCloneEnabled) {
                $submoduleFlags .= ' --shallow-submodules';
            }
        }

        $git_clone_command = "git clone{$depthFlag}{$submoduleFlags} -b {$escapedBranch}";
        if ($only_checkout) {
            $git_clone_command = "git clone{$depthFlag}{$submoduleFlags} --no-checkout -b {$escapedBranch}";
        }
        if ($pull_request_id !== 0) {
            $pr_branch_name = "pr-{$pull_request_id}-coolify";
        }
        if ($this->deploymentType() === 'source') {
            $source_html_url = data_get($this, 'source.html_url');
            $url = parse_url(filter_var($source_html_url, FILTER_SANITIZE_URL));
            $source_html_url_host = $url['host'];
            $source_html_url_scheme = $url['scheme'];

            if ($this->source->getMorphClass() === GithubApp::class) {
                if ($this->source->is_public) {
                    $fullRepoUrl = "{$this->source->html_url}/{$customRepository}";
                    $escapedRepoUrl = escapeshellarg("{$this->source->html_url}/{$customRepository}");
                    $git_clone_command = "{$git_clone_command} {$escapedRepoUrl} {$escapedBaseDir}";
                    if (! $only_checkout) {
                        $git_clone_command = $this->setGitImportSettings($deployment_uuid, $git_clone_command, public: true, commit: $commit);
                    }
                    if ($exec_in_docker) {
                        $commands->push(executeInDocker($deployment_uuid, $git_clone_command));
                    } else {
                        $commands->push($git_clone_command);
                    }
                } else {
                    $github_access_token = generateGithubInstallationToken($this->source);
                    $encodedToken = rawurlencode($github_access_token);
                    if ($exec_in_docker) {
                        $repoUrl = "$source_html_url_scheme://x-access-token:$encodedToken@$source_html_url_host/{$customRepository}.git";
                        $escapedRepoUrl = escapeshellarg($repoUrl);
                        $git_clone_command = "{$git_clone_command} {$escapedRepoUrl} {$escapedBaseDir}";
                        $fullRepoUrl = $repoUrl;
                    } else {
                        $repoUrl = "$source_html_url_scheme://x-access-token:$encodedToken@$source_html_url_host/{$customRepository}";
                        $escapedRepoUrl = escapeshellarg($repoUrl);
                        $git_clone_command = "{$git_clone_command} {$escapedRepoUrl} {$escapedBaseDir}";
                        $fullRepoUrl = $repoUrl;
                    }
                    if (! $only_checkout) {
                        $git_clone_command = $this->setGitImportSettings($deployment_uuid, $git_clone_command, public: false, commit: $commit);
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
                    $escapedPrBranch = escapeshellarg($branch);
                    if ($exec_in_docker) {
                        $commands->push(executeInDocker($deployment_uuid, "cd {$escapedBaseDir} && git fetch origin {$escapedPrBranch} && $git_checkout_command"));
                    } else {
                        $commands->push("cd {$escapedBaseDir} && git fetch origin {$escapedPrBranch} && $git_checkout_command");
                    }
                }

                return [
                    'commands' => $commands->implode(' && '),
                    'branch' => $branch,
                    'fullRepoUrl' => $fullRepoUrl,
                ];
            }

            if ($this->source->getMorphClass() === GitlabApp::class) {
                $gitlabSource = $this->source;
                $private_key = data_get($gitlabSource, 'privateKey.private_key');

                if ($private_key) {
                    $fullRepoUrl = $customRepository;
                    $private_key = base64_encode($private_key);
                    $gitlabPort = $gitlabSource->custom_port ?? 22;
                    $escapedCustomRepository = escapeshellarg($customRepository);
                    $gitlabSshCommand = "GIT_SSH_COMMAND=\"ssh -o ConnectTimeout=30 -p {$gitlabPort} -o Port={$gitlabPort} -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i /root/.ssh/id_rsa\"";
                    $git_clone_command_base = "{$gitlabSshCommand} {$git_clone_command} {$escapedCustomRepository} {$escapedBaseDir}";
                    if ($only_checkout) {
                        $git_clone_command = $git_clone_command_base;
                    } else {
                        $git_clone_command = $this->setGitImportSettings($deployment_uuid, $git_clone_command_base, commit: $commit, git_ssh_command: $gitlabSshCommand);
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
                        $branch = "merge-requests/{$pull_request_id}/head:$pr_branch_name";
                        if ($exec_in_docker) {
                            $commands->push(executeInDocker($deployment_uuid, "echo 'Checking out $branch'"));
                        } else {
                            $commands->push("echo 'Checking out $branch'");
                        }
                        $git_clone_command = "{$git_clone_command} && cd {$escapedBaseDir} && GIT_SSH_COMMAND=\"ssh -o ConnectTimeout=30 -p {$gitlabPort} -o Port={$gitlabPort} -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i /root/.ssh/id_rsa\" git fetch origin $branch && ".$this->buildGitCheckoutCommand($pr_branch_name);
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

                // GitLab source without private key — use URL as-is (supports user-embedded basic auth)
                $fullRepoUrl = $customRepository;
                $escapedCustomRepository = escapeshellarg($customRepository);
                $git_clone_command = "{$git_clone_command} {$escapedCustomRepository} {$escapedBaseDir}";
                $git_clone_command = $this->setGitImportSettings($deployment_uuid, $git_clone_command, public: true, commit: $commit);

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
        if ($this->deploymentType() === 'deploy_key') {
            $fullRepoUrl = $customRepository;
            $private_key = data_get($this, 'private_key.private_key');
            if (is_null($private_key)) {
                throw new RuntimeException('Private key not found. Please add a private key to the application and try again.');
            }
            $private_key = base64_encode($private_key);
            $escapedCustomRepository = escapeshellarg($customRepository);
            $deployKeySshCommand = "GIT_SSH_COMMAND=\"ssh -o ConnectTimeout=30 -p {$customPort} -o Port={$customPort} -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i /root/.ssh/id_rsa\"";
            $git_clone_command_base = "{$deployKeySshCommand} {$git_clone_command} {$escapedCustomRepository} {$escapedBaseDir}";
            if ($only_checkout) {
                $git_clone_command = $git_clone_command_base;
            } else {
                $git_clone_command = $this->setGitImportSettings($deployment_uuid, $git_clone_command_base, commit: $commit, git_ssh_command: $deployKeySshCommand);
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
                    $git_clone_command = "{$git_clone_command} && cd {$escapedBaseDir} && GIT_SSH_COMMAND=\"ssh -o ConnectTimeout=30 -p {$customPort} -o Port={$customPort} -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i /root/.ssh/id_rsa\" git fetch origin $branch && ".$this->buildGitCheckoutCommand($pr_branch_name);
                } elseif ($git_type === 'github' || $git_type === 'gitea') {
                    $branch = "pull/{$pull_request_id}/head:$pr_branch_name";
                    if ($exec_in_docker) {
                        $commands->push(executeInDocker($deployment_uuid, "echo 'Checking out $branch'"));
                    } else {
                        $commands->push("echo 'Checking out $branch'");
                    }
                    $git_clone_command = "{$git_clone_command} && cd {$escapedBaseDir} && GIT_SSH_COMMAND=\"ssh -o ConnectTimeout=30 -p {$customPort} -o Port={$customPort} -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i /root/.ssh/id_rsa\" git fetch origin $branch && ".$this->buildGitCheckoutCommand($pr_branch_name);
                } elseif ($git_type === 'bitbucket') {
                    if ($exec_in_docker) {
                        $commands->push(executeInDocker($deployment_uuid, "echo 'Checking out $branch'"));
                    } else {
                        $commands->push("echo 'Checking out $branch'");
                    }
                    $git_clone_command = "{$git_clone_command} && cd {$escapedBaseDir} && GIT_SSH_COMMAND=\"ssh -o ConnectTimeout=30 -p {$customPort} -o Port={$customPort} -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i /root/.ssh/id_rsa\" ".$this->buildGitCheckoutCommand($commit);
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
            $escapedCustomRepository = escapeshellarg($customRepository);
            $git_clone_command = "{$git_clone_command} {$escapedCustomRepository} {$escapedBaseDir}";
            $git_clone_command = $this->setGitImportSettings($deployment_uuid, $git_clone_command, public: true, commit: $commit);

            if ($pull_request_id !== 0) {
                if ($git_type === 'gitlab') {
                    $branch = "merge-requests/{$pull_request_id}/head:$pr_branch_name";
                    if ($exec_in_docker) {
                        $commands->push(executeInDocker($deployment_uuid, "echo 'Checking out $branch'"));
                    } else {
                        $commands->push("echo 'Checking out $branch'");
                    }
                    $git_clone_command = "{$git_clone_command} && cd {$escapedBaseDir} && GIT_SSH_COMMAND=\"ssh -o ConnectTimeout=30 -p {$customPort} -o Port={$customPort} -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i /root/.ssh/id_rsa\" git fetch origin $branch && ".$this->buildGitCheckoutCommand($pr_branch_name);
                } elseif ($git_type === 'github' || $git_type === 'gitea') {
                    $branch = "pull/{$pull_request_id}/head:$pr_branch_name";
                    if ($exec_in_docker) {
                        $commands->push(executeInDocker($deployment_uuid, "echo 'Checking out $branch'"));
                    } else {
                        $commands->push("echo 'Checking out $branch'");
                    }
                    $git_clone_command = "{$git_clone_command} && cd {$escapedBaseDir} && GIT_SSH_COMMAND=\"ssh -o ConnectTimeout=30 -p {$customPort} -o Port={$customPort} -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i /root/.ssh/id_rsa\" git fetch origin $branch && ".$this->buildGitCheckoutCommand($pr_branch_name);
                } elseif ($git_type === 'bitbucket') {
                    if ($exec_in_docker) {
                        $commands->push(executeInDocker($deployment_uuid, "echo 'Checking out $branch'"));
                    } else {
                        $commands->push("echo 'Checking out $branch'");
                    }
                    $git_clone_command = "{$git_clone_command} && cd {$escapedBaseDir} && GIT_SSH_COMMAND=\"ssh -o ConnectTimeout=30 -p {$customPort} -o Port={$customPort} -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i /root/.ssh/id_rsa\" ".$this->buildGitCheckoutCommand($commit);
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
            throw new RuntimeException($e->getMessage());
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

    public function parse(int $pull_request_id = 0, ?int $preview_id = null, ?string $commit = null)
    {
        if ((int) $this->compose_parsing_version >= 3) {
            return applicationParser($this, $pull_request_id, $preview_id, $commit);
        } elseif ($this->docker_compose_raw) {
            return parseDockerComposeFile(resource: $this, isNew: false, pull_request_id: $pull_request_id, preview_id: $preview_id);
        } else {
            return collect([]);
        }
    }

    public function loadComposeFile($isInit = false, ?string $restoreBaseDirectory = null, ?string $restoreDockerComposeLocation = null)
    {
        // Use provided restore values or capture current values as fallback
        $initialDockerComposeLocation = $restoreDockerComposeLocation ?? $this->docker_compose_location;
        $initialBaseDirectory = $restoreBaseDirectory ?? $this->base_directory;
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
            throw new RuntimeException('Failed to read Git source. Please verify repository access and try again.');
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
            // Restore original values on failure only
            $this->docker_compose_location = $initialDockerComposeLocation;
            $this->base_directory = $initialBaseDirectory;
            $this->save();

            if (str($e->getMessage())->contains('No such file')) {
                throw new RuntimeException("Docker Compose file not found at: $workdir$composeFile (branch: {$this->git_branch})<br><br>Check if you used the right extension (.yaml or .yml) in the compose file name.");
            }
            if (str($e->getMessage())->contains('fatal: repository') && str($e->getMessage())->contains('does not exist')) {
                if ($this->deploymentType() === 'deploy_key') {
                    throw new RuntimeException('Your deploy key does not have access to the repository. Please check your deploy key and try again.');
                }
                throw new RuntimeException('Repository does not exist. Please check your repository URL and try again.');
            }
            throw new RuntimeException('Failed to read the Docker Compose file from the repository.');
        } finally {
            // Cleanup only - restoration happens in catch block
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
                $decoded = json_decode($this->docker_compose_domains, true);
                $json = collect(is_array($decoded) ? $decoded : []);
                $normalized = collect();
                foreach ($json as $key => $value) {
                    $normalizedKey = (string) str($key)->replace('-', '_')->replace('.', '_');
                    $normalized->put($normalizedKey, $value);
                }
                $json = $normalized;
                $services = collect(data_get($parsedServices, 'services', []));
                foreach ($services as $name => $service) {
                    if (str($name)->contains('-') || str($name)->contains('.')) {
                        $replacedName = str($name)->replace('-', '_')->replace('.', '_');
                        $services->put((string) $replacedName, $service);
                        $services->forget((string) $name);
                    }
                }
                $names = collect($services)->keys()->toArray();
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
            // Restore original values before throwing
            $this->docker_compose_location = $initialDockerComposeLocation;
            $this->base_directory = $initialBaseDirectory;
            $this->save();

            throw new RuntimeException("Docker Compose file not found at: $workdir$composeFile (branch: {$this->git_branch})<br><br>Check if you used the right extension (.yaml or .yml) in the compose file name.");
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
        if (mb_detect_encoding($customLabels, 'UTF-8', true) === false) {
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
        $escapedTarget = escapeshellarg($target);
        $command = "git checkout {$escapedTarget}";

        if ($this->settings->is_git_submodules_enabled) {
            $command .= ' && git submodule update --init --recursive';
        }

        return $command;
    }

    private function parseWatchPaths($value)
    {
        if ($value) {
            $watch_paths = collect(explode("\n", $value))
                ->map(function (string $path): string {
                    // Trim whitespace
                    $path = trim($path);

                    if (str_starts_with($path, '!')) {
                        $negation = '!';
                        $pathWithoutNegation = substr($path, 1);
                        $pathWithoutNegation = ltrim(trim($pathWithoutNegation), '/');

                        return $negation.$pathWithoutNegation;
                    }

                    return ltrim($path, '/');
                })
                ->filter(function (string $path): bool {
                    return strlen($path) > 0;
                });

            return trim($watch_paths->implode("\n"));
        }
    }

    public function watchPaths(): Attribute
    {
        return Attribute::make(
            set: function ($value) {
                if ($value) {
                    return $this->parseWatchPaths($value);
                }
            }
        );
    }

    public function matchWatchPaths(Collection $modified_files, ?Collection $watch_paths): Collection
    {
        return self::matchPaths($modified_files, $watch_paths);
    }

    /**
     * Static method to match paths against watch patterns with negation support
     * Uses order-based matching: last matching pattern wins
     */
    public static function matchPaths(Collection $modified_files, ?Collection $watch_paths): Collection
    {
        if (is_null($watch_paths) || $watch_paths->isEmpty()) {
            return collect([]);
        }

        return $modified_files->filter(function ($file) use ($watch_paths) {
            $shouldInclude = null; // null means no patterns matched

            // Process patterns in order - last match wins
            foreach ($watch_paths as $pattern) {
                $pattern = trim($pattern);
                if (empty($pattern)) {
                    continue;
                }

                $isExclusion = str_starts_with($pattern, '!');
                $matchPattern = $isExclusion ? substr($pattern, 1) : $pattern;

                if (self::globMatch($matchPattern, $file)) {
                    // This pattern matches - it determines the current state
                    $shouldInclude = ! $isExclusion;
                }
            }

            // If no patterns matched and we only have exclusion patterns, include by default
            if ($shouldInclude === null) {
                // Check if we only have exclusion patterns
                $hasInclusionPatterns = $watch_paths->contains(fn ($p) => ! str_starts_with(trim($p), '!'));

                return ! $hasInclusionPatterns;
            }

            return $shouldInclude;
        })->values();
    }

    /**
     * Check if a path matches a glob pattern
     * Supports: *, **, ?, [abc], [!abc]
     */
    public static function globMatch(string $pattern, string $path): bool
    {
        $regex = self::globToRegex($pattern);

        return preg_match($regex, $path) === 1;
    }

    /**
     * Convert a glob pattern to a regular expression
     */
    public static function globToRegex(string $pattern): string
    {
        $regex = '';
        $inGroup = false;
        $chars = str_split($pattern);
        $len = count($chars);

        for ($i = 0; $i < $len; $i++) {
            $c = $chars[$i];

            switch ($c) {
                case '*':
                    // Check for **
                    if ($i + 1 < $len && $chars[$i + 1] === '*') {
                        // ** matches any number of directories
                        $regex .= '.*';
                        $i++; // Skip next *
                        // Skip optional /
                        if ($i + 1 < $len && $chars[$i + 1] === '/') {
                            $i++;
                        }
                    } else {
                        // * matches anything except /
                        $regex .= '[^/]*';
                    }
                    break;

                case '?':
                    // ? matches any single character except /
                    $regex .= '[^/]';
                    break;

                case '[':
                    // Character class
                    $inGroup = true;
                    $regex .= '[';
                    // Check for negation
                    if ($i + 1 < $len && ($chars[$i + 1] === '!' || $chars[$i + 1] === '^')) {
                        $regex .= '^';
                        $i++;
                    }
                    break;

                case ']':
                    if ($inGroup) {
                        $inGroup = false;
                        $regex .= ']';
                    } else {
                        $regex .= preg_quote($c, '#');
                    }
                    break;

                case '.':
                case '(':
                case ')':
                case '+':
                case '{':
                case '}':
                case '$':
                case '^':
                case '|':
                case '\\':
                    // Escape regex special characters
                    $regex .= '\\'.$c;
                    break;

                default:
                    $regex .= $c;
                    break;
            }
        }

        // Wrap in delimiters and anchors
        return '#^'.$regex.'$#';
    }

    public function normalizeWatchPaths(): void
    {
        if (is_null($this->watch_paths)) {
            return;
        }

        $normalized = $this->parseWatchPaths($this->watch_paths);
        if ($normalized !== $this->watch_paths) {
            $this->watch_paths = $normalized;
            $this->save();
        }
    }

    public function isWatchPathsTriggered(Collection $modified_files): bool
    {
        if (is_null($this->watch_paths)) {
            return false;
        }

        $this->normalizeWatchPaths();

        $watch_paths = collect(explode("\n", $this->watch_paths));

        if ($watch_paths->isEmpty()) {
            return false;
        }
        $matches = $this->matchWatchPaths($modified_files, $watch_paths);

        return $matches->count() > 0;
    }

    public function getFilesFromServer(bool $isInit = false)
    {
        getFilesystemVolumesFromServer($this, $isInit);
    }

    public function parseHealthcheckFromDockerfile($dockerfile, bool $isInit = false)
    {
        $dockerfile = str($dockerfile)->trim()->explode("\n");
        $hasHealthcheck = str($dockerfile)->contains('HEALTHCHECK');

        // Always check if healthcheck was removed, regardless of health_check_enabled setting
        if (! $hasHealthcheck && $this->custom_healthcheck_found) {
            // HEALTHCHECK was removed from Dockerfile, reset to defaults
            $this->custom_healthcheck_found = false;
            $this->health_check_interval = 5;
            $this->health_check_timeout = 5;
            $this->health_check_retries = 10;
            $this->health_check_start_period = 5;
            $this->save();

            return;
        }

        if ($hasHealthcheck && ($this->isHealthcheckDisabled() || $isInit)) {
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
                $interval = str($healthcheckCommand)->match('/--interval=([0-9]+[a-zµ]*)/');
                $timeout = str($healthcheckCommand)->match('/--timeout=([0-9]+[a-zµ]*)/');
                $start_period = str($healthcheckCommand)->match('/--start-period=([0-9]+[a-zµ]*)/');
                $retries = str($healthcheckCommand)->match('/--retries=(\d+)/');

                if ($interval->isNotEmpty()) {
                    $this->health_check_interval = parseDockerfileInterval($interval);
                }
                if ($timeout->isNotEmpty()) {
                    $this->health_check_timeout = parseDockerfileInterval($timeout);
                }
                if ($start_period->isNotEmpty()) {
                    $this->health_check_start_period = parseDockerfileInterval($start_period);
                }
                if ($retries->isNotEmpty()) {
                    $this->health_check_retries = $retries->toInteger();
                }
                if ($interval || $timeout || $start_period || $retries) {
                    $this->custom_healthcheck_found = true;
                    $this->save();
                }
            }
        }
    }

    public function getLimits(): array
    {
        return [
            'limits_memory' => $this->limits_memory,
            'limits_memory_swap' => $this->limits_memory_swap,
            'limits_memory_swappiness' => $this->limits_memory_swappiness,
            'limits_memory_reservation' => $this->limits_memory_reservation,
            'limits_cpus' => $this->limits_cpus,
            'limits_cpuset' => $this->limits_cpuset,
            'limits_cpu_shares' => $this->limits_cpu_shares,
        ];
    }

    public function generateConfig($is_json = false)
    {
        $generator = new ConfigurationGenerator($this);

        if ($is_json) {
            return $generator->toJson();
        }

        return $generator->toArray();
    }

    /**
     * Apply configuration from a coolify.json config array or JSON string.
     *
     * @param  array|string  $config  The configuration to apply
     * @param  bool  $fromRepository  If true, the config comes from a repository's coolify.json file.
     *                                In this case, the 'source' section (repository, branch, commit_sha)
     *                                is ignored since these are already set from the actual git source.
     *                                If false (default), the config is from a copy-paste import and
     *                                source settings will be applied if present.
     */
    public function setConfig(array|string $config, bool $fromRepository = false): void
    {
        // Accept both JSON string and array
        if (is_string($config)) {
            $config = json_decode($config, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Invalid JSON format: '.json_last_error_msg());
            }
        }

        // Apply git source settings only for copy-paste import (not when loading from repository)
        // When loading from a repository, the git source is already set from the actual repo
        if (! $fromRepository && ($source = data_get($config, 'source'))) {
            if (($value = data_get($source, 'repository')) !== null) {
                $this->git_repository = $value;
            }
            if (($value = data_get($source, 'branch')) !== null) {
                $this->git_branch = $value;
            }
            if (($value = data_get($source, 'commit_sha')) !== null) {
                $this->git_commit_sha = $value;
            }
        }

        // Apply build settings
        if ($build = data_get($config, 'build')) {
            $buildMappings = [
                'type' => 'build_pack',
                'base_directory' => 'base_directory',
                'publish_directory' => 'publish_directory',
                'dockerfile_location' => 'dockerfile_location',
                'docker_compose_location' => 'docker_compose_location',
                'install_command' => 'install_command',
                'build_command' => 'build_command',
                'start_command' => 'start_command',
                'watch_paths' => 'watch_paths',
                'static_image' => 'static_image',
                'dockerfile_target_build' => 'dockerfile_target_build',
                'custom_docker_run_options' => 'custom_docker_run_options',
                'docker_compose_custom_start_command' => 'docker_compose_custom_start_command',
                'docker_compose_custom_build_command' => 'docker_compose_custom_build_command',
            ];

            foreach ($buildMappings as $configKey => $modelKey) {
                if (($value = data_get($build, $configKey)) !== null) {
                    $this->{$modelKey} = $value;
                }
            }
        }

        // Apply domain settings
        if ($domains = data_get($config, 'domains')) {
            if (($value = data_get($domains, 'ports_exposes')) !== null) {
                $this->ports_exposes = $value;
            }
            if (($value = data_get($domains, 'ports_mappings')) !== null) {
                $this->ports_mappings = $value;
            }
            if (($value = data_get($domains, 'redirect')) !== null) {
                $this->redirect = $value;
            }
            if (($value = data_get($domains, 'custom_nginx_configuration')) !== null) {
                $this->custom_nginx_configuration = $value;
            }
        }

        // Apply network aliases
        if (($value = data_get($config, 'network_aliases')) !== null) {
            $this->custom_network_aliases = is_array($value) ? implode(',', $value) : $value;
        }

        // Apply HTTP Basic Authentication
        if ($httpAuth = data_get($config, 'http_basic_auth')) {
            if (($value = data_get($httpAuth, 'enabled')) !== null) {
                $this->is_http_basic_auth_enabled = $value;
            }
            if (($value = data_get($httpAuth, 'username')) !== null) {
                $this->http_basic_auth_username = $value;
            }
            if (($value = data_get($httpAuth, 'password')) !== null) {
                $this->http_basic_auth_password = $value;
            }
        }

        // Apply health check settings
        if ($healthCheck = data_get($config, 'health_check')) {
            $healthMappings = [
                'enabled' => 'health_check_enabled',
                'path' => 'health_check_path',
                'port' => 'health_check_port',
                'host' => 'health_check_host',
                'method' => 'health_check_method',
                'return_code' => 'health_check_return_code',
                'scheme' => 'health_check_scheme',
                'response_text' => 'health_check_response_text',
                'interval' => 'health_check_interval',
                'timeout' => 'health_check_timeout',
                'retries' => 'health_check_retries',
                'start_period' => 'health_check_start_period',
            ];

            foreach ($healthMappings as $configKey => $modelKey) {
                if (($value = data_get($healthCheck, $configKey)) !== null) {
                    $this->{$modelKey} = $value;
                }
            }
        }

        // Apply resource limits
        if ($limits = data_get($config, 'limits')) {
            $limitMappings = [
                'memory' => 'limits_memory',
                'memory_swap' => 'limits_memory_swap',
                'memory_swappiness' => 'limits_memory_swappiness',
                'memory_reservation' => 'limits_memory_reservation',
                'cpus' => 'limits_cpus',
                'cpuset' => 'limits_cpuset',
                'cpu_shares' => 'limits_cpu_shares',
            ];

            foreach ($limitMappings as $configKey => $modelKey) {
                if (($value = data_get($limits, $configKey)) !== null) {
                    $this->{$modelKey} = $value;
                }
            }
        }

        // Apply name and description
        if (($value = data_get($config, 'name')) !== null) {
            $this->name = $value;
        }
        if (($value = data_get($config, 'description')) !== null) {
            $this->description = $value;
        }

        $this->save();

        // Apply application settings
        if ($settings = data_get($config, 'settings')) {
            $settingMappings = [
                'is_static' => 'is_static',
                'is_spa' => 'is_spa',
                'is_auto_deploy_enabled' => 'is_auto_deploy_enabled',
                'is_force_https_enabled' => 'is_force_https_enabled',
                'is_preview_deployments_enabled' => 'is_preview_deployments_enabled',
                'is_pr_deployments_public_enabled' => 'is_pr_deployments_public_enabled',
                'is_git_submodules_enabled' => 'is_git_submodules_enabled',
                'is_git_lfs_enabled' => 'is_git_lfs_enabled',
                'is_git_shallow_clone_enabled' => 'is_git_shallow_clone_enabled',
                'is_build_server_enabled' => 'is_build_server_enabled',
                'is_preserve_repository_enabled' => 'is_preserve_repository_enabled',
                'is_container_label_escape_enabled' => 'is_container_label_escape_enabled',
                'is_container_label_readonly_enabled' => 'is_container_label_readonly_enabled',
                'use_build_secrets' => 'use_build_secrets',
                'inject_build_args_to_dockerfile' => 'inject_build_args_to_dockerfile',
                'include_source_commit_in_build' => 'include_source_commit_in_build',
                'is_debug_enabled' => 'is_debug_enabled',
                'is_consistent_container_name_enabled' => 'is_consistent_container_name_enabled',
                'connect_to_docker_network' => 'connect_to_docker_network',
                'custom_internal_name' => 'custom_internal_name',
                'is_env_sorting_enabled' => 'is_env_sorting_enabled',
            ];

            $settingsToUpdate = [];
            foreach ($settingMappings as $configKey => $modelKey) {
                if (($value = data_get($settings, $configKey)) !== null) {
                    $settingsToUpdate[$modelKey] = $value;
                }
            }

            if (! empty($settingsToUpdate)) {
                $this->settings()->update($settingsToUpdate);
            }
        }

        // Apply pre/post deployment commands
        if ($commands = data_get($config, 'deployment_commands')) {
            if (($value = data_get($commands, 'pre_deployment_command')) !== null) {
                $this->pre_deployment_command = $value;
            }
            if (($value = data_get($commands, 'pre_deployment_command_container')) !== null) {
                $this->pre_deployment_command_container = $value;
            }
            if (($value = data_get($commands, 'post_deployment_command')) !== null) {
                $this->post_deployment_command = $value;
            }
            if (($value = data_get($commands, 'post_deployment_command_container')) !== null) {
                $this->post_deployment_command_container = $value;
            }
            $this->save();
        }

        // Apply preview settings
        if ($preview = data_get($config, 'preview')) {
            if (($value = data_get($preview, 'preview_url_template')) !== null) {
                $this->preview_url_template = $value;
                $this->save();
            }
        }

        // Apply swarm settings
        if ($swarm = data_get($config, 'swarm')) {
            if (($value = data_get($swarm, 'swarm_replicas')) !== null) {
                $this->swarm_replicas = $value;
            }
            if (($value = data_get($swarm, 'swarm_placement_constraints')) !== null) {
                $this->swarm_placement_constraints = $value;
            }
            $this->save();
        }

        // Apply docker registry settings
        if ($dockerRegistry = data_get($config, 'docker_registry')) {
            if (($value = data_get($dockerRegistry, 'image')) !== null) {
                $this->docker_registry_image_name = $value;
            }
            if (($value = data_get($dockerRegistry, 'tag')) !== null) {
                $this->docker_registry_image_tag = $value;
            }
            $this->save();
        }

        // Apply persistent storages (Volume Mounts)
        if ($persistentStorages = data_get($config, 'persistent_storages')) {
            foreach ($persistentStorages as $storage) {
                $name = data_get($storage, 'name');
                $mountPath = data_get($storage, 'mount_path');

                if (empty($name) || empty($mountPath)) {
                    continue;
                }

                // Check if storage with this name already exists
                $existingStorage = $this->persistentStorages()
                    ->where('name', $name)
                    ->first();

                if ($existingStorage) {
                    // Update existing storage
                    $existingStorage->update([
                        'mount_path' => $mountPath,
                        'host_path' => data_get($storage, 'host_path'),
                    ]);
                } else {
                    // Create new storage
                    LocalPersistentVolume::create([
                        'name' => $name,
                        'mount_path' => $mountPath,
                        'host_path' => data_get($storage, 'host_path'),
                        'resource_id' => $this->id,
                        'resource_type' => $this->getMorphClass(),
                    ]);
                }
            }
        }

        // Apply file mounts
        if ($fileMounts = data_get($config, 'file_mounts')) {
            foreach ($fileMounts as $file) {
                $mountPath = data_get($file, 'mount_path');

                if (empty($mountPath)) {
                    continue;
                }

                // Ensure mount_path starts with /
                $mountPath = str($mountPath)->start('/')->value();

                // Determine fs_path - use provided or generate default
                $fsPath = data_get($file, 'fs_path');
                if (empty($fsPath)) {
                    $fsPath = application_configuration_dir().'/'.$this->uuid.$mountPath;
                }

                // Check if file mount already exists
                $existingFile = $this->fileStorages()
                    ->where('mount_path', $mountPath)
                    ->where('is_directory', false)
                    ->first();

                if ($existingFile) {
                    // Update existing file mount
                    $existingFile->update([
                        'fs_path' => $fsPath,
                        'content' => data_get($file, 'content'),
                    ]);
                } else {
                    // Create new file mount
                    LocalFileVolume::create([
                        'fs_path' => $fsPath,
                        'mount_path' => $mountPath,
                        'content' => data_get($file, 'content'),
                        'is_directory' => false,
                        'resource_id' => $this->id,
                        'resource_type' => $this->getMorphClass(),
                    ]);
                }
            }
        }

        // Apply directory mounts
        if ($directoryMounts = data_get($config, 'directory_mounts')) {
            foreach ($directoryMounts as $dir) {
                $sourcePath = data_get($dir, 'source_path');
                $mountPath = data_get($dir, 'mount_path');

                if (empty($sourcePath) || empty($mountPath)) {
                    continue;
                }

                // Ensure paths start with /
                $sourcePath = str($sourcePath)->start('/')->value();
                $mountPath = str($mountPath)->start('/')->value();

                // Check if directory mount already exists
                $existingDir = $this->fileStorages()
                    ->where('mount_path', $mountPath)
                    ->where('is_directory', true)
                    ->first();

                if ($existingDir) {
                    // Update existing directory mount
                    $existingDir->update([
                        'fs_path' => $sourcePath,
                    ]);
                } else {
                    // Create new directory mount
                    LocalFileVolume::create([
                        'fs_path' => $sourcePath,
                        'mount_path' => $mountPath,
                        'is_directory' => true,
                        'resource_id' => $this->id,
                        'resource_type' => $this->getMorphClass(),
                    ]);
                }
            }
        }

        // Apply scheduled tasks
        if ($scheduledTasks = data_get($config, 'scheduled_tasks')) {
            foreach ($scheduledTasks as $task) {
                $name = data_get($task, 'name');
                $command = data_get($task, 'command');
                $frequency = data_get($task, 'frequency');

                if (empty($name) || empty($command) || empty($frequency)) {
                    continue;
                }

                // Check if scheduled task with this name already exists
                $existingTask = $this->scheduled_tasks()
                    ->where('name', $name)
                    ->first();

                if ($existingTask) {
                    // Update existing task
                    $existingTask->update([
                        'command' => $command,
                        'frequency' => $frequency,
                        'container' => data_get($task, 'container'),
                        'enabled' => data_get($task, 'enabled', true),
                        'timeout' => data_get($task, 'timeout', 300),
                    ]);
                } else {
                    // Create new scheduled task
                    ScheduledTask::create([
                        'name' => $name,
                        'command' => $command,
                        'frequency' => $frequency,
                        'container' => data_get($task, 'container'),
                        'enabled' => data_get($task, 'enabled', true),
                        'timeout' => data_get($task, 'timeout', 300),
                        'application_id' => $this->id,
                        'team_id' => $this->team_id,
                    ]);
                }
            }
        }

        // Apply environment variables
        $this->applyEnvironmentVariablesFromConfig($config);
    }

    protected function applyEnvironmentVariablesFromConfig(array $config): void
    {
        $envVars = data_get($config, 'environment_variables', []);

        // Process production environment variables
        $productionVars = data_get($envVars, 'production', []);
        foreach ($productionVars as $var) {
            $this->createEnvironmentVariableFromConfig($var, false);
        }

        // Process preview environment variables
        $previewVars = data_get($envVars, 'preview', []);
        foreach ($previewVars as $var) {
            $this->createEnvironmentVariableFromConfig($var, true);
        }
    }

    protected function createEnvironmentVariableFromConfig(array $var, bool $isPreview): void
    {
        $key = data_get($var, 'key');
        $value = data_get($var, 'value', '');

        if (empty($key)) {
            return;
        }

        // Skip SERVICE_* variables as they are auto-generated
        if (str($key)->startsWith('SERVICE_')) {
            return;
        }

        // Resolve magic environment variables
        $value = $this->resolveMagicEnvironmentVariable($value);

        // Check if variable already exists
        // Use correct relationship based on preview flag (environment_variables() already filters is_preview=false)
        $existingVar = ($isPreview ? $this->environment_variables_preview() : $this->environment_variables())
            ->where('key', $key)
            ->first();

        if ($existingVar) {
            // Update existing variable
            // Defaults: is_buildtime=true, is_runtime=true (available during build AND runtime)
            $existingVar->update([
                'value' => $value,
                'is_multiline' => data_get($var, 'is_multiline', false),
                'is_literal' => data_get($var, 'is_literal', false),
                'is_buildtime' => data_get($var, 'is_buildtime', true),
                'is_runtime' => data_get($var, 'is_runtime', true),
            ]);
        } else {
            // Create new variable
            // Defaults: is_buildtime=true, is_runtime=true (available during build AND runtime)
            EnvironmentVariable::create([
                'key' => $key,
                'value' => $value,
                'is_preview' => $isPreview,
                'is_multiline' => data_get($var, 'is_multiline', false),
                'is_literal' => data_get($var, 'is_literal', false),
                'is_buildtime' => data_get($var, 'is_buildtime', true),
                'is_runtime' => data_get($var, 'is_runtime', true),
                'resourceable_id' => $this->id,
                'resourceable_type' => $this->getMorphClass(),
            ]);
        }
    }

    protected function resolveMagicEnvironmentVariable(string $value): string
    {
        // Check if this is a magic SERVICE_* value
        if (! str_starts_with($value, 'SERVICE_')) {
            return $value;
        }

        // Extract command from SERVICE_COMMAND format
        $command = substr($value, strlen('SERVICE_'));

        // Map coolify.json magic values to generateEnvValue() commands
        // generateEnvValue uses: PASSWORD, PASSWORD_64, USER, BASE64_XX, REALBASE64_XX, HEX_XX, etc.
        $commandMappings = [
            'PASSWORD' => 'PASSWORD_64',        // SERVICE_PASSWORD -> 64-char password
            'UUID' => null,                      // SERVICE_UUID -> handled separately (Cuid2)
        ];

        // Check for direct mapping first
        if (array_key_exists($command, $commandMappings)) {
            if ($commandMappings[$command] === null) {
                // SERVICE_UUID - Generate a Cuid2 (not supported by generateEnvValue)
                return (string) new Cuid2;
            }
            $command = $commandMappings[$command];
        }

        // SERVICE_USER -> generateEnvValue('USER') returns 16-char random, add prefix
        if ($command === 'USER') {
            $generated = generateEnvValue('USER');

            return $generated ? 'user_'.substr($generated, 0, 8) : 'user_'.Str::random(8);
        }

        // SERVICE_BASE64_XX -> REALBASE64_XX (actual base64 encoding)
        if (preg_match('/^BASE64_(\d+)$/', $command, $matches)) {
            $length = (int) $matches[1];
            // Clamp between 8 and 256
            $length = max(8, min(256, $length));
            // Map to REALBASE64_XX if supported, otherwise generate directly
            $realBase64Command = "REALBASE64_{$length}";
            $generated = generateEnvValue($realBase64Command);
            if ($generated) {
                return $generated;
            }

            // Fallback: generate base64 directly
            return base64_encode(Str::random($length));
        }

        // Try generateEnvValue for PASSWORD_XX and other commands
        $generated = generateEnvValue($command);
        if ($generated !== null) {
            return $generated;
        }

        // If generateEnvValue returns null, handle PASSWORD_XX directly
        if (preg_match('/^PASSWORD_(\d+)$/', $command, $matches)) {
            $length = (int) $matches[1];
            $length = max(8, min(256, $length));

            return Str::password(length: $length, symbols: false);
        }

        // Not a recognized magic value, return as-is
        return $value;
    }

    public function generateRepositoryConfig(): array
    {
        $config = [
            'version' => '1.0',
            'name' => $this->name,
        ];

        if ($this->description) {
            $config['description'] = $this->description;
        }

        // Git source - optional, allows full app configuration from JSON
        $gitSource = [];
        if ($this->git_repository) {
            $gitSource['repository'] = $this->git_repository;
        }
        if ($this->git_branch) {
            $gitSource['branch'] = $this->git_branch;
        }
        if ($this->git_commit_sha && $this->git_commit_sha !== 'HEAD') {
            $gitSource['commit_sha'] = $this->git_commit_sha;
        }
        if (! empty($gitSource)) {
            $config['source'] = $gitSource;
        }

        // Build settings - only include non-default values
        $build = [
            'type' => $this->build_pack,
        ];
        if ($this->base_directory && $this->base_directory !== '/') {
            $build['base_directory'] = $this->base_directory;
        }
        if ($this->publish_directory) {
            $build['publish_directory'] = $this->publish_directory;
        }
        if ($this->dockerfile_location && $this->dockerfile_location !== '/Dockerfile') {
            $build['dockerfile_location'] = $this->dockerfile_location;
        }
        if ($this->docker_compose_location && $this->docker_compose_location !== '/docker-compose.yml' && $this->docker_compose_location !== '/docker-compose.yaml') {
            $build['docker_compose_location'] = $this->docker_compose_location;
        }
        if ($this->install_command) {
            $build['install_command'] = $this->install_command;
        }
        if ($this->build_command) {
            $build['build_command'] = $this->build_command;
        }
        if ($this->start_command) {
            $build['start_command'] = $this->start_command;
        }
        if ($this->watch_paths) {
            $build['watch_paths'] = $this->watch_paths;
        }
        // Advanced build options - exclude default static_image
        if ($this->static_image && $this->static_image !== 'nginx:alpine') {
            $build['static_image'] = $this->static_image;
        }
        if ($this->dockerfile_target_build) {
            $build['dockerfile_target_build'] = $this->dockerfile_target_build;
        }
        if ($this->custom_docker_run_options) {
            $build['custom_docker_run_options'] = $this->custom_docker_run_options;
        }
        if ($this->docker_compose_custom_start_command) {
            $build['docker_compose_custom_start_command'] = $this->docker_compose_custom_start_command;
        }
        if ($this->docker_compose_custom_build_command) {
            $build['docker_compose_custom_build_command'] = $this->docker_compose_custom_build_command;
        }
        $config['build'] = $build;

        // Domain settings - only include non-default values
        $domains = [];
        if ($this->ports_exposes && $this->ports_exposes !== '80') {
            $domains['ports_exposes'] = $this->ports_exposes;
        }
        if ($this->ports_mappings) {
            $domains['ports_mappings'] = $this->ports_mappings;
        }
        if ($this->redirect && $this->redirect !== 'both') {
            $domains['redirect'] = $this->redirect;
        }
        if ($this->custom_nginx_configuration) {
            $domains['custom_nginx_configuration'] = $this->custom_nginx_configuration;
        }
        if (! empty($domains)) {
            $config['domains'] = $domains;
        }

        // Environment variables (with placeholder values for security)
        // Actual secret values are NOT exported - users must fill in the values
        // Only include non-default flags to keep the export clean
        // Filter out SERVICE_* variables as they are auto-generated by Coolify
        $mapEnvVar = function ($var) {
            $result = [
                'key' => $var->key,
                'value' => '${'.$var->key.'}',
            ];
            // Only include flags if they differ from defaults (true)
            if (! $var->is_buildtime) {
                $result['is_buildtime'] = false;
            }
            if (! $var->is_runtime) {
                $result['is_runtime'] = false;
            }
            // Only include if true (default is false)
            if ($var->is_literal) {
                $result['is_literal'] = true;
            }
            if ($var->is_multiline) {
                $result['is_multiline'] = true;
            }

            return $result;
        };

        // Filter out auto-generated SERVICE_* variables (SERVICE_FQDN_*, SERVICE_URL_*, SERVICE_PASSWORD_*, etc.)
        $filterServiceVars = function ($var) {
            return ! str($var->key)->startsWith('SERVICE_');
        };

        $productionVars = $this->environment_variables()
            ->where('is_preview', false)
            ->get()
            ->filter($filterServiceVars)
            ->values()
            ->map($mapEnvVar)
            ->toArray();

        $previewVars = $this->environment_variables()
            ->where('is_preview', true)
            ->get()
            ->filter($filterServiceVars)
            ->values()
            ->map($mapEnvVar)
            ->toArray();

        if (! empty($productionVars) || ! empty($previewVars)) {
            $config['environment_variables'] = [];
            if (! empty($productionVars)) {
                $config['environment_variables']['production'] = $productionVars;
            }
            if (! empty($previewVars)) {
                $config['environment_variables']['preview'] = $previewVars;
            }
        }

        // Health check settings - only include non-default values
        // Default: health_check_enabled=false (changed in 2024_07_19 migration)
        $healthCheck = [];
        if ($this->health_check_enabled) {
            $healthCheck['enabled'] = true;
        }
        if ($this->health_check_path && $this->health_check_path !== '/') {
            $healthCheck['path'] = $this->health_check_path;
        }
        if ($this->health_check_port) {
            $healthCheck['port'] = $this->health_check_port;
        }
        if ($this->health_check_host && $this->health_check_host !== 'localhost') {
            $healthCheck['host'] = $this->health_check_host;
        }
        if ($this->health_check_method && $this->health_check_method !== 'GET') {
            $healthCheck['method'] = $this->health_check_method;
        }
        if ($this->health_check_return_code && $this->health_check_return_code !== 200) {
            $healthCheck['return_code'] = $this->health_check_return_code;
        }
        if ($this->health_check_scheme && $this->health_check_scheme !== 'http') {
            $healthCheck['scheme'] = $this->health_check_scheme;
        }
        if ($this->health_check_response_text) {
            $healthCheck['response_text'] = $this->health_check_response_text;
        }
        if ($this->health_check_interval && $this->health_check_interval !== 5) {
            $healthCheck['interval'] = $this->health_check_interval;
        }
        if ($this->health_check_timeout && $this->health_check_timeout !== 5) {
            $healthCheck['timeout'] = $this->health_check_timeout;
        }
        if ($this->health_check_retries && $this->health_check_retries !== 10) {
            $healthCheck['retries'] = $this->health_check_retries;
        }
        if ($this->health_check_start_period && $this->health_check_start_period !== 5) {
            $healthCheck['start_period'] = $this->health_check_start_period;
        }
        if (! empty($healthCheck)) {
            $config['health_check'] = $healthCheck;
        }

        // Resource limits (only if non-default)
        $limits = [];
        if ($this->limits_memory && $this->limits_memory !== '0') {
            $limits['memory'] = $this->limits_memory;
        }
        if ($this->limits_memory_swap && $this->limits_memory_swap !== '0') {
            $limits['memory_swap'] = $this->limits_memory_swap;
        }
        if ($this->limits_memory_swappiness && $this->limits_memory_swappiness !== 60) {
            $limits['memory_swappiness'] = $this->limits_memory_swappiness;
        }
        if ($this->limits_memory_reservation && $this->limits_memory_reservation !== '0') {
            $limits['memory_reservation'] = $this->limits_memory_reservation;
        }
        if ($this->limits_cpus && $this->limits_cpus !== '0') {
            $limits['cpus'] = $this->limits_cpus;
        }
        if ($this->limits_cpuset && $this->limits_cpuset !== '0') {
            $limits['cpuset'] = $this->limits_cpuset;
        }
        if ($this->limits_cpu_shares && $this->limits_cpu_shares !== 1024) {
            $limits['cpu_shares'] = $this->limits_cpu_shares;
        }
        if (! empty($limits)) {
            $config['limits'] = $limits;
        }

        // Application settings - only export non-default values
        $settings = $this->settings;
        $configSettings = [];

        // Settings with default=false - only include if true
        if ($settings->is_static) {
            $configSettings['is_static'] = true;
        }
        if ($settings->is_spa) {
            $configSettings['is_spa'] = true;
        }
        if ($settings->is_preview_deployments_enabled) {
            $configSettings['is_preview_deployments_enabled'] = true;
        }
        if ($settings->is_pr_deployments_public_enabled) {
            $configSettings['is_pr_deployments_public_enabled'] = true;
        }
        if ($settings->is_build_server_enabled) {
            $configSettings['is_build_server_enabled'] = true;
        }
        if ($settings->is_preserve_repository_enabled) {
            $configSettings['is_preserve_repository_enabled'] = true;
        }
        if ($settings->use_build_secrets) {
            $configSettings['use_build_secrets'] = true;
        }
        if ($settings->include_source_commit_in_build) {
            $configSettings['include_source_commit_in_build'] = true;
        }
        if ($settings->is_debug_enabled) {
            $configSettings['is_debug_enabled'] = true;
        }
        if ($settings->is_consistent_container_name_enabled) {
            $configSettings['is_consistent_container_name_enabled'] = true;
        }
        if ($settings->connect_to_docker_network) {
            $configSettings['connect_to_docker_network'] = true;
        }
        if ($settings->is_env_sorting_enabled) {
            $configSettings['is_env_sorting_enabled'] = true;
        }

        // Settings with default=true - only include if false
        if (! $settings->is_auto_deploy_enabled) {
            $configSettings['is_auto_deploy_enabled'] = false;
        }
        if (! $settings->is_force_https_enabled) {
            $configSettings['is_force_https_enabled'] = false;
        }
        if (! $settings->is_git_submodules_enabled) {
            $configSettings['is_git_submodules_enabled'] = false;
        }
        if (! $settings->is_git_lfs_enabled) {
            $configSettings['is_git_lfs_enabled'] = false;
        }
        if (! $settings->is_git_shallow_clone_enabled) {
            $configSettings['is_git_shallow_clone_enabled'] = false;
        }
        if (! $settings->is_container_label_escape_enabled) {
            $configSettings['is_container_label_escape_enabled'] = false;
        }
        if (! $settings->is_container_label_readonly_enabled) {
            $configSettings['is_container_label_readonly_enabled'] = false;
        }
        if (! $settings->inject_build_args_to_dockerfile) {
            $configSettings['inject_build_args_to_dockerfile'] = false;
        }

        // String settings - only include if set
        if ($settings->custom_internal_name) {
            $configSettings['custom_internal_name'] = $settings->custom_internal_name;
        }

        if (! empty($configSettings)) {
            $config['settings'] = $configSettings;
        }

        // Network aliases
        if ($this->custom_network_aliases) {
            $config['network_aliases'] = explode(',', $this->custom_network_aliases);
        }

        // HTTP Basic Authentication (don't export password for security)
        if ($this->is_http_basic_auth_enabled) {
            $config['http_basic_auth'] = [
                'enabled' => true,
                'username' => $this->http_basic_auth_username,
                // Password is intentionally not exported for security reasons
            ];
        }

        // Pre/Post deployment commands
        $deploymentCommands = [];
        if ($this->pre_deployment_command) {
            $deploymentCommands['pre_deployment_command'] = $this->pre_deployment_command;
        }
        if ($this->pre_deployment_command_container) {
            $deploymentCommands['pre_deployment_command_container'] = $this->pre_deployment_command_container;
        }
        if ($this->post_deployment_command) {
            $deploymentCommands['post_deployment_command'] = $this->post_deployment_command;
        }
        if ($this->post_deployment_command_container) {
            $deploymentCommands['post_deployment_command_container'] = $this->post_deployment_command_container;
        }
        if (! empty($deploymentCommands)) {
            $config['deployment_commands'] = $deploymentCommands;
        }

        // Preview settings - only include if different from default
        if ($this->preview_url_template && $this->preview_url_template !== '{{pr_id}}.{{domain}}') {
            $config['preview'] = [
                'preview_url_template' => $this->preview_url_template,
            ];
        }

        // Swarm settings
        $swarm = [];
        if ($this->swarm_replicas && $this->swarm_replicas !== 1) {
            $swarm['swarm_replicas'] = $this->swarm_replicas;
        }
        if ($this->swarm_placement_constraints) {
            $swarm['swarm_placement_constraints'] = $this->swarm_placement_constraints;
        }
        if (! empty($swarm)) {
            $config['swarm'] = $swarm;
        }

        // Docker registry settings
        $dockerRegistry = [];
        if ($this->docker_registry_image_name) {
            $dockerRegistry['image'] = $this->docker_registry_image_name;
        }
        if ($this->docker_registry_image_tag) {
            $dockerRegistry['tag'] = $this->docker_registry_image_tag;
        }
        if (! empty($dockerRegistry)) {
            $config['docker_registry'] = $dockerRegistry;
        }

        // Persistent storages (Volume Mounts)
        $persistentStorages = $this->persistentStorages->map(function ($storage) {
            $storageConfig = [
                'name' => $storage->name,
                'mount_path' => $storage->mount_path,
            ];
            if ($storage->host_path) {
                $storageConfig['host_path'] = $storage->host_path;
            }

            return $storageConfig;
        })->toArray();

        if (! empty($persistentStorages)) {
            $config['persistent_storages'] = $persistentStorages;
        }

        // File Mounts (single files with content)
        $fileMounts = $this->fileStorages()
            ->where('is_directory', false)
            ->get()
            ->map(function ($file) {
                $fileConfig = [
                    'mount_path' => $file->mount_path,
                ];
                // Include content if not binary
                if ($file->content && $file->content !== '[binary file]') {
                    $fileConfig['content'] = $file->content;
                }
                // Include fs_path if different from mount_path
                if ($file->fs_path && $file->fs_path !== $file->mount_path) {
                    $fileConfig['fs_path'] = $file->fs_path;
                }

                return $fileConfig;
            })->toArray();

        if (! empty($fileMounts)) {
            $config['file_mounts'] = $fileMounts;
        }

        // Directory Mounts
        $directoryMounts = $this->fileStorages()
            ->where('is_directory', true)
            ->get()
            ->map(function ($dir) {
                return [
                    'source_path' => $dir->fs_path,
                    'mount_path' => $dir->mount_path,
                ];
            })->toArray();

        if (! empty($directoryMounts)) {
            $config['directory_mounts'] = $directoryMounts;
        }

        // Scheduled Tasks (cron jobs)
        $scheduledTasks = $this->scheduled_tasks->map(function ($task) {
            $taskConfig = [
                'name' => $task->name,
                'command' => $task->command,
                'frequency' => $task->frequency,
            ];
            if ($task->container) {
                $taskConfig['container'] = $task->container;
            }
            if (! $task->enabled) {
                $taskConfig['enabled'] = false;
            }
            if ($task->timeout && $task->timeout !== 300) {
                $taskConfig['timeout'] = $task->timeout;
            }

            return $taskConfig;
        })->toArray();

        if (! empty($scheduledTasks)) {
            $config['scheduled_tasks'] = $scheduledTasks;
        }

        return $config;
    }
}
