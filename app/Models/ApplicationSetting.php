<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

#[OA\Schema(
    description: 'Application settings.',
    type: 'object',
    properties: [
        'is_static' => ['type' => 'boolean'],
        'is_git_submodules_enabled' => ['type' => 'boolean'],
        'is_git_lfs_enabled' => ['type' => 'boolean'],
        'is_auto_deploy_enabled' => ['type' => 'boolean'],
        'is_force_https_enabled' => ['type' => 'boolean'],
        'is_debug_enabled' => ['type' => 'boolean'],
        'is_preview_deployments_enabled' => ['type' => 'boolean'],
        'is_log_drain_enabled' => ['type' => 'boolean'],
        'is_gpu_enabled' => ['type' => 'boolean'],
        'gpu_driver' => ['type' => 'string', 'nullable' => true],
        'gpu_count' => ['type' => 'string', 'nullable' => true],
        'gpu_device_ids' => ['type' => 'string', 'nullable' => true],
        'gpu_options' => ['type' => 'string', 'nullable' => true],
        'is_include_timestamps' => ['type' => 'boolean'],
        'is_swarm_only_worker_nodes' => ['type' => 'boolean'],
        'is_raw_compose_deployment_enabled' => ['type' => 'boolean'],
        'is_build_server_enabled' => ['type' => 'boolean'],
        'is_consistent_container_name_enabled' => ['type' => 'boolean'],
        'is_gzip_enabled' => ['type' => 'boolean'],
        'traefik_certificate_resolver' => ['type' => 'string', 'nullable' => true, 'description' => 'Traefik certificate resolver override. Null uses the server setting, "none" uses no resolver.'],
        'is_stripprefix_enabled' => ['type' => 'boolean'],
        'is_permanent_redirect_enabled' => ['type' => 'boolean', 'description' => 'Use permanent (301/308) instead of temporary (302/307) proxy redirects.'],
        'connect_to_docker_network' => ['type' => 'boolean'],
        'custom_internal_name' => ['type' => 'string', 'nullable' => true],
        'custom_container_name_prefix' => ['type' => 'string', 'nullable' => true],
        'is_container_label_escape_enabled' => ['type' => 'boolean'],
        'is_env_sorting_enabled' => ['type' => 'boolean'],
        'include_coolify_default_variables' => ['type' => 'boolean'],
        'is_container_label_readonly_enabled' => ['type' => 'boolean'],
        'is_preserve_repository_enabled' => ['type' => 'boolean'],
        'disable_build_cache' => ['type' => 'boolean'],
        'is_spa' => ['type' => 'boolean'],
        'is_git_shallow_clone_enabled' => ['type' => 'boolean'],
        'is_pr_deployments_public_enabled' => ['type' => 'boolean'],
        'use_build_secrets' => ['type' => 'boolean'],
        'redact_all_env_values_in_logs' => ['type' => 'boolean', 'description' => 'Hide every environment variable value in logs. When false, only locked values are hidden.'],
        'inject_build_args_to_dockerfile' => ['type' => 'boolean'],
        'include_source_commit_in_build' => ['type' => 'boolean'],
        'docker_images_to_keep' => ['type' => 'integer'],
        'stop_grace_period' => ['type' => 'integer', 'nullable' => true],
        'rolling_update_drain_seconds' => ['type' => 'integer', 'description' => 'Seconds to wait after the new container is healthy before old containers stop during a rolling update. 0 turns the wait off.'],
    ]
)]
class ApplicationSetting extends Model
{
    /**
     * Keeps generated names (prefix, timestamp and for compose apps the service name) well below the
     * 63 character DNS label limit, with room for a longer suffix in the future.
     */
    public const MAX_CONTAINER_NAME_PREFIX_LENGTH = 30;

    public const GPU_COUNT_PATTERN = '/^(all|\d+)$/';

    protected $casts = [
        'is_static' => 'boolean',
        'is_spa' => 'boolean',
        'is_build_server_enabled' => 'boolean',
        'is_preserve_repository_enabled' => 'boolean',
        'is_container_label_escape_enabled' => 'boolean',
        'is_container_label_readonly_enabled' => 'boolean',
        'use_build_secrets' => 'boolean',
        'inject_build_args_to_dockerfile' => 'boolean',
        'include_source_commit_in_build' => 'boolean',
        'is_auto_deploy_enabled' => 'boolean',
        'is_force_https_enabled' => 'boolean',
        'is_debug_enabled' => 'boolean',
        'is_preview_deployments_enabled' => 'boolean',
        'is_pr_deployments_public_enabled' => 'boolean',
        'is_git_submodules_enabled' => 'boolean',
        'is_git_lfs_enabled' => 'boolean',
        'is_git_shallow_clone_enabled' => 'boolean',
        'docker_images_to_keep' => 'integer',
        'stop_grace_period' => 'integer',
        'rolling_update_drain_seconds' => 'integer',
        'is_log_drain_enabled' => 'boolean',
        'is_gpu_enabled' => 'boolean',
        'is_include_timestamps' => 'boolean',
        'is_swarm_only_worker_nodes' => 'boolean',
        'is_raw_compose_deployment_enabled' => 'boolean',
        'is_consistent_container_name_enabled' => 'boolean',
        'is_gzip_enabled' => 'boolean',
        'is_stripprefix_enabled' => 'boolean',
        'is_permanent_redirect_enabled' => 'boolean',
        'connect_to_docker_network' => 'boolean',
        'is_env_sorting_enabled' => 'boolean',
        'include_coolify_default_variables' => 'boolean',
        'redact_all_env_values_in_logs' => 'boolean',
        'disable_build_cache' => 'boolean',
    ];

    protected $fillable = [
        'application_id',
        'is_static',
        'is_git_submodules_enabled',
        'is_git_lfs_enabled',
        'is_auto_deploy_enabled',
        'is_force_https_enabled',
        'is_debug_enabled',
        'is_preview_deployments_enabled',
        'is_log_drain_enabled',
        'is_gpu_enabled',
        'gpu_driver',
        'gpu_count',
        'gpu_device_ids',
        'gpu_options',
        'is_include_timestamps',
        'is_swarm_only_worker_nodes',
        'is_raw_compose_deployment_enabled',
        'is_build_server_enabled',
        'is_consistent_container_name_enabled',
        'is_gzip_enabled',
        'traefik_certificate_resolver',
        'is_stripprefix_enabled',
        'is_permanent_redirect_enabled',
        'connect_to_docker_network',
        'custom_internal_name',
        'custom_container_name_prefix',
        'is_container_label_escape_enabled',
        'is_env_sorting_enabled',
        'include_coolify_default_variables',
        'is_container_label_readonly_enabled',
        'is_preserve_repository_enabled',
        'disable_build_cache',
        'is_spa',
        'is_git_shallow_clone_enabled',
        'is_pr_deployments_public_enabled',
        'use_build_secrets',
        'redact_all_env_values_in_logs',
        'inject_build_args_to_dockerfile',
        'include_source_commit_in_build',
        'docker_images_to_keep',
        'stop_grace_period',
        'rolling_update_drain_seconds',
    ];

    /**
     * Like custom container names, a prefix must be unique per server so that uuid, custom container
     * name and prefix each identify one container when resolving connections.
     */
    public static function isContainerNamePrefixInUse(string $prefix, Server $server, ?int $ignoreApplicationId = null): bool
    {
        return $server->applications()->contains(function (Application $application) use ($prefix, $ignoreApplicationId) {
            return $application->id !== $ignoreApplicationId
                && in_array($prefix, [$application->uuid, $application->settings->custom_container_name_prefix, $application->settings->custom_internal_name], true);
        });
    }

    public function stopGracePeriodSeconds(): int
    {
        if (
            $this->stop_grace_period >= MIN_STOP_GRACE_PERIOD_SECONDS &&
            $this->stop_grace_period <= MAX_STOP_GRACE_PERIOD_SECONDS
        ) {
            return $this->stop_grace_period;
        }

        return DEFAULT_STOP_GRACE_PERIOD_SECONDS;
    }

    public function rollingUpdateDrainSeconds(): int
    {
        return min(max($this->rolling_update_drain_seconds ?? DEFAULT_ROLLING_UPDATE_DRAIN_SECONDS, 0), MAX_ROLLING_UPDATE_DRAIN_SECONDS);
    }

    public function deploymentStopGracePeriodSeconds(): int
    {
        if (isDev() && $this->stop_grace_period === null) {
            return MIN_STOP_GRACE_PERIOD_SECONDS;
        }

        return $this->stopGracePeriodSeconds();
    }

    /**
     * The compose device request (deploy.resources.reservations.devices item) for the GPU settings.
     * A count wins over device IDs. Options that cannot be parsed are left out.
     *
     * @return array{capabilities: list<string>, driver?: string, count?: int|string, device_ids?: list<string>, options?: array<string, string>}
     */
    public function gpuDeviceReservation(): array
    {
        $device = ['capabilities' => ['gpu']];
        $driver = trim((string) $this->gpu_driver);
        if ($driver !== '') {
            $device['driver'] = $driver;
        }

        $count = trim((string) $this->gpu_count);
        $deviceIds = str((string) $this->gpu_device_ids)->explode(',')->map(fn (string $id) => trim($id))->filter(fn (string $id) => $id !== '')->values()->all();
        if ($count !== '') {
            $device['count'] = $count === 'all' ? 'all' : (int) $count;
        } elseif ($deviceIds !== []) {
            $device['device_ids'] = $deviceIds;
        }

        $options = self::parseGpuOptions($this->gpu_options);
        if ($options) {
            $device['options'] = $options;
        }

        return $device;
    }

    /**
     * Parse GPU driver options written as a YAML/JSON mapping or as key=value pairs, one per line.
     *
     * @return array<string, string>|null null when the value is not a flat mapping
     */
    public static function parseGpuOptions(?string $options): ?array
    {
        $options = trim((string) $options);
        if ($options === '') {
            return [];
        }

        try {
            $parsed = Yaml::parse($options);
        } catch (ParseException) {
            $parsed = null;
        }
        if (is_array($parsed) && $parsed !== [] && ! array_is_list($parsed)) {
            $mapping = [];
            foreach ($parsed as $key => $value) {
                if (! is_scalar($value)) {
                    return null;
                }
                $mapping[(string) $key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            }

            return $mapping;
        }

        $mapping = [];
        foreach (preg_split('/\R/', $options) as $pair) {
            $pair = trim($pair);
            if ($pair === '') {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, null);
            if ($value === null || trim($key) === '') {
                return null;
            }
            $mapping[trim($key)] = trim($value);
        }

        return $mapping;
    }

    public function isStatic(): Attribute
    {
        return Attribute::make(
            set: function ($value) {
                if ($value) {
                    $this->application->ports_exposes = 80;
                }
                $this->application->save();

                return $value;
            }
        );
    }

    public function application()
    {
        return $this->belongsTo(Application::class);
    }
}
