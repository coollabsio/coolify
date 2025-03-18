<?php

namespace App\Services;

use App\Models\Application;
use Symfony\Component\Yaml\Yaml;

class ConfigurationGenerator
{
    protected array $config = [];

    public function __construct(protected Application $resource)
    {
        $this->generateConfig();
    }

    protected function generateConfig(): void
    {
        // Commented out some stuffs to simplify the initial release feature scope.
        // Commented out stuffs are required for passing coolify.json without a source code, like you can define git_repository, git_branch, etc.
        // Current scope is only for having coolify.json next to your source code in a git repo.
        if ($this->resource instanceof Application) {
            $this->config = [
                'name' => $this->resource->name,
                'description' => $this->resource->description,
                // 'coolify' => [
                //     'project_uuid' => $this->resource->project()->uuid,
                //     'environment_uuid' => $this->resource->environment->uuid,
                //     'destination_type' => $this->resource->destination_type,
                //     'destination_id' => $this->resource->destination_id,
                //     'source_type' => $this->resource->source_type,
                //     'source_id' => $this->resource->source_id,
                //     'private_key_id' => $this->resource->private_key_id,
                // ],
                // 'source' => [
                //     'git_repository' => $this->resource->git_repository,
                //     'git_branch' => $this->resource->git_branch,
                //     'git_commit_sha' => $this->resource->git_commit_sha,
                //     'repository_project_id' => $this->resource->repository_project_id,
                // ],
                'build' => [
                    'build_pack' => $this->resource->build_pack,
                    'static_image' => $this->resource->static_image,
                    'base_directory' => $this->resource->base_directory,
                    'publish_directory' => $this->resource->publish_directory,
                    'install_command' => $this->resource->install_command,
                    'build_command' => $this->resource->build_command,
                    'start_command' => $this->resource->start_command,
                    'watch_paths' => $this->resource->watch_paths,
                    'dockerfile' => [
                        // 'content' => $this->resource->dockerfile,
                        'location' => $this->resource->dockerfile_location,
                        'target_build' => $this->resource->dockerfile_target_build,
                    ],
                    'docker_compose' => [
                        // 'content' => $this->resource->docker_compose,
                        'location' => $this->resource->docker_compose_location,
                        // 'raw' => $this->resource->docker_compose_raw,
                        'domains' => $this->resource->docker_compose_domains,
                        'custom_start_command' => $this->resource->docker_compose_custom_start_command,
                        'custom_build_command' => $this->resource->docker_compose_custom_build_command,
                        // 'parsing_version' => $this->resource->compose_parsing_version,
                    ],

                ],
                'docker_custom_options' => $this->resource->custom_docker_options,
                'labels' => $this->resource->custom_labels,
                'deployment' => [
                    'pre_deployment' => [
                        'command' => $this->resource->pre_deployment_command,
                        'container' => $this->resource->pre_deployment_command_container,
                    ],
                    'post_deployment' => [
                        'command' => $this->resource->post_deployment_command,
                        'container' => $this->resource->post_deployment_command_container,
                    ],
                ],
                'network' => [
                    'domains' => [
                        'fqdn' => $this->resource->fqdn,
                        'redirect' => $this->resource->redirect,
                        'custom_nginx_configuration' => $this->resource->custom_nginx_configuration,
                    ],
                    'ports' => [
                        'expose' => $this->resource->ports_exposes,
                        'mappings' => $this->resource->ports_mappings,
                    ],
                ],
                'health_check' => [
                    'enabled' => $this->resource->health_check_enabled,
                    'path' => $this->resource->health_check_path,
                    'port' => $this->resource->health_check_port,
                    'host' => $this->resource->health_check_host,
                    'method' => $this->resource->health_check_method,
                    'return_code' => $this->resource->health_check_return_code,
                    'scheme' => $this->resource->health_check_scheme,
                    'response_text' => $this->resource->health_check_response_text,
                    'interval' => $this->resource->health_check_interval,
                    'timeout' => $this->resource->health_check_timeout,
                    'retries' => $this->resource->health_check_retries,
                    'start_period' => $this->resource->health_check_start_period,
                ],
                'resources' => [
                    'memory' => [
                        'limit' => $this->resource->limits_memory,
                        'swap' => $this->resource->limits_memory_swap,
                        'swappiness' => $this->resource->limits_memory_swappiness,
                        'reservation' => $this->resource->limits_memory_reservation,
                    ],
                    'cpu' => [
                        'limit' => $this->resource->limits_cpus,
                        'set' => $this->resource->limits_cpuset,
                        'shares' => $this->resource->limits_cpu_shares,
                    ],
                ],
                'preview' => [
                    'url_template' => $this->resource->preview_url_template,
                ],
                'webhooks' => [
                    'secrets' => [
                        'github' => $this->resource->manual_webhook_secret_github,
                        'gitlab' => $this->resource->manual_webhook_secret_gitlab,
                        'bitbucket' => $this->resource->manual_webhook_secret_bitbucket,
                        'gitea' => $this->resource->manual_webhook_secret_gitea,
                    ],
                ],
                'environment_variables' => [
                    'production' => $this->getEnvironmentVariables(),
                    'preview' => $this->getPreviewEnvironmentVariables(),
                ],
                'settings' => $this->getApplicationSettings(),
                'persistent_storages' => $this->getPersistentStorages(),
                'scheduled_tasks' => $this->getScheduledTasks(),
                'tags' => $this->getTags(),
            ];
        }

        $this->config = $this->cleanupNullEmptyValues($this->config);
    }

    protected function cleanupNullEmptyValues(array $config): array
    {
        return collect($config)->map(function ($value) {
            if (is_array($value)) {
                return $this->cleanupNullEmptyValues($value);
            }

            return $value;
        })->filter(function ($value) {
            return filled($value) &&
                   (! is_array($value) || ! empty($value));
        })->toArray();
    }

    protected function getTags(): array
    {
        return $this->resource->tags()->pluck('name')->toArray();
    }

    protected function getScheduledTasks(): array
    {
        $removedKeys = ['id', 'uuid', 'application_id', 'created_at', 'updated_at', 'team_id'];

        return $this->resource->scheduled_tasks()->get()->map(function ($task) use ($removedKeys) {
            return collect($task->attributesToArray())->filter(function ($value, $key) use ($removedKeys) {
                return ! in_array($key, $removedKeys);
            })->toArray();
        })->toArray();
    }

    protected function getPersistentStorages(): array
    {
        $removedKeys = ['id', 'application_id', 'created_at', 'updated_at', 'resource_type', 'resource_id', 'name', 'is_readonly'];

        return $this->resource->persistentStorages()->get()->map(function ($storage) use ($removedKeys) {
            return collect($storage->attributesToArray())->filter(function ($value, $key) use ($removedKeys) {
                return ! in_array($key, $removedKeys);
            })->toArray();
        })->toArray();
    }

    protected function getPreview(): array
    {
        return [
            'preview_url_template' => $this->resource->preview_url_template,
        ];
    }

    protected function getDockerRegistryImage(): array
    {
        return [
            'image' => $this->resource->docker_registry_image_name,
            'tag' => $this->resource->docker_registry_image_tag,
        ];
    }

    protected function getEnvironmentVariables(): array
    {
        $variables = collect([]);
        foreach ($this->resource->environment_variables as $env) {
            $variables->push([
                'key' => $env->key,
                'value' => $env->value,
                'is_required' => $env->is_required,
                'is_build_time' => $env->is_build_time,
                'is_preview' => $env->is_preview,
                'is_multiline' => $env->is_multiline,
            ]);
        }

        return $variables->toArray();
    }

    protected function getPreviewEnvironmentVariables(): array
    {
        $variables = collect([]);
        foreach ($this->resource->environment_variables_preview as $env) {
            $variables->push([
                'key' => $env->key,
                'value' => $env->value,
                'is_build_time' => $env->is_build_time,
                'is_preview' => $env->is_preview,
                'is_multiline' => $env->is_multiline,
            ]);
        }

        return $variables->toArray();
    }

    protected function getApplicationSettings(): array
    {
        $removedKeys = ['id', 'application_id', 'created_at', 'updated_at', 'is_swarm_only_worker_nodes'];
        $settings = $this->resource->settings->attributesToArray();
        $settings = collect($settings)->filter(function ($value, $key) use ($removedKeys) {
            return ! in_array($key, $removedKeys);
        })->sortBy(function ($value, $key) {
            return $key;
        })->toArray();

        return [
            'deployment' => [
                'auto_deploy' => data_get($settings, 'is_auto_deploy_enabled', false),
                'preview_deployments' => data_get($settings, 'is_preview_deployments_enabled', false),
                'preserve_repository' => data_get($settings, 'is_preserve_repository_enabled', false),
                'build_server' => data_get($settings, 'is_build_server_enabled', false),
                'debug_mode' => data_get($settings, 'is_debug_enabled', false),
            ],
            'git' => [
                'lfs_enabled' => data_get($settings, 'is_git_lfs_enabled', false),
                'submodules_enabled' => data_get($settings, 'is_git_submodules_enabled', false),
            ],
            'docker' => [
                'network' => [
                    'connect' => data_get($settings, 'connect_to_docker_network', false),
                    'custom_internal_name' => data_get($settings, 'custom_internal_name'),
                ],
                'container' => [
                    'consistent_name' => data_get($settings, 'is_consistent_container_name_enabled', false),
                    'label_escape' => data_get($settings, 'is_container_label_escape_enabled', true),
                    'label_readonly' => data_get($settings, 'is_container_label_readonly_enabled', true),
                ],
                'build' => [
                    'disable_cache' => data_get($settings, 'disable_build_cache', false),
                    'raw_compose_deployment' => data_get($settings, 'is_raw_compose_deployment_enabled', false),
                ],
            ],
            'gpu' => [
                'enabled' => data_get($settings, 'is_gpu_enabled', false),
                'driver' => data_get($settings, 'gpu_driver', 'nvidia'),
            ],
            'logging' => [
                'drain_enabled' => data_get($settings, 'is_log_drain_enabled', false),
                'include_timestamps' => data_get($settings, 'is_include_timestamps', false),
            ],
            'environment' => [
                'sort_variables' => data_get($settings, 'is_env_sorting_enabled', true),
            ],
            'proxy' => [
                'force_https' => data_get($settings, 'is_force_https_enabled', true),
                'gzip' => data_get($settings, 'is_gzip_enabled', true),
                'stripprefix' => data_get($settings, 'is_stripprefix_enabled', true),
            ],
            'type' => [
                'static' => data_get($settings, 'is_static', false),
            ],
        ];
    }

    public function saveJson(string $path): void
    {
        file_put_contents($path, json_encode($this->config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function saveYaml(string $path): void
    {
        file_put_contents($path, Yaml::dump($this->config, 6, 2));
    }

    public function toArray(): array
    {
        return $this->config;
    }

    public function toJson(): string
    {
        return json_encode($this->config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    public function toYaml(): string
    {
        return Yaml::dump($this->config, 6, 2);
    }
}
