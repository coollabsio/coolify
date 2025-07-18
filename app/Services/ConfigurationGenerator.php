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
        if ($this->resource instanceof Application) {
            $this->config = [
                'id' => $this->resource->id,
                'name' => $this->resource->name,
                'uuid' => $this->resource->uuid,
                'description' => $this->resource->description,
                'coolify_details' => [
                    'project_uuid' => $this->resource->project()->uuid,
                    'environment_uuid' => $this->resource->environment->uuid,

                    'destination_type' => $this->resource->destination_type,
                    'destination_id' => $this->resource->destination_id,
                    'source_type' => $this->resource->source_type,
                    'source_id' => $this->resource->source_id,
                    'private_key_id' => $this->resource->private_key_id,
                ],

                'post_deployment_command' => $this->resource->post_deployment_command,
                'post_deployment_command_container' => $this->resource->post_deployment_command_container,
                'pre_deployment_command' => $this->resource->pre_deployment_command,
                'pre_deployment_command_container' => $this->resource->pre_deployment_command_container,
                'build' => [
                    'type' => $this->resource->build_pack,
                    'static_image' => $this->resource->static_image,
                    'base_directory' => $this->resource->base_directory,
                    'publish_directory' => $this->resource->publish_directory,
                    'dockerfile' => $this->resource->dockerfile,
                    'dockerfile_location' => $this->resource->dockerfile_location,
                    'dockerfile_target_build' => $this->resource->dockerfile_target_build,
                    'custom_docker_run_options' => $this->resource->custom_docker_options,
                    'compose_parsing_version' => $this->resource->compose_parsing_version,
                    'docker_compose' => $this->resource->docker_compose,
                    'docker_compose_location' => $this->resource->docker_compose_location,
                    'docker_compose_raw' => $this->resource->docker_compose_raw,
                    'docker_compose_domains' => $this->resource->docker_compose_domains,
                    'docker_compose_custom_start_command' => $this->resource->docker_compose_custom_start_command,
                    'docker_compose_custom_build_command' => $this->resource->docker_compose_custom_build_command,
                    'install_command' => $this->resource->install_command,
                    'build_command' => $this->resource->build_command,
                    'start_command' => $this->resource->start_command,
                    'watch_paths' => $this->resource->watch_paths,
                ],
                'source' => [
                    'git_repository' => $this->resource->git_repository,
                    'git_branch' => $this->resource->git_branch,
                    'git_commit_sha' => $this->resource->git_commit_sha,
                    'repository_project_id' => $this->resource->repository_project_id,
                ],
                'docker_registry_image' => $this->getDockerRegistryImage(),
                'domains' => [
                    'fqdn' => $this->resource->fqdn,
                    'ports_exposes' => $this->resource->ports_exposes,
                    'ports_mappings' => $this->resource->ports_mappings,
                    'redirect' => $this->resource->redirect,
                    'custom_nginx_configuration' => $this->resource->custom_nginx_configuration,
                ],
                'environment_variables' => [
                    'production' => $this->getEnvironmentVariables(),
                    'preview' => $this->getPreviewEnvironmentVariables(),
                ],
                'settings' => $this->getApplicationSettings(),
                'preview' => $this->getPreview(),
                'limits' => $this->resource->getLimits(),
                'health_check' => [
                    'health_check_path' => $this->resource->health_check_path,
                    'health_check_port' => $this->resource->health_check_port,
                    'health_check_host' => $this->resource->health_check_host,
                    'health_check_method' => $this->resource->health_check_method,
                    'health_check_return_code' => $this->resource->health_check_return_code,
                    'health_check_scheme' => $this->resource->health_check_scheme,
                    'health_check_response_text' => $this->resource->health_check_response_text,
                    'health_check_interval' => $this->resource->health_check_interval,
                    'health_check_timeout' => $this->resource->health_check_timeout,
                    'health_check_retries' => $this->resource->health_check_retries,
                    'health_check_start_period' => $this->resource->health_check_start_period,
                    'health_check_enabled' => $this->resource->health_check_enabled,
                ],
                'webhooks_secrets' => [
                    'manual_webhook_secret_github' => $this->resource->manual_webhook_secret_github,
                    'manual_webhook_secret_gitlab' => $this->resource->manual_webhook_secret_gitlab,
                    'manual_webhook_secret_bitbucket' => $this->resource->manual_webhook_secret_bitbucket,
                    'manual_webhook_secret_gitea' => $this->resource->manual_webhook_secret_gitea,
                ],
                'swarm' => [
                    'swarm_replicas' => $this->resource->swarm_replicas,
                    'swarm_placement_constraints' => $this->resource->swarm_placement_constraints,
                ],
            ];
        }
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
        $removedKeys = ['id', 'application_id', 'created_at', 'updated_at'];
        $settings = $this->resource->settings->attributesToArray();
        $settings = collect($settings)->filter(function ($value, $key) use ($removedKeys) {
            return ! in_array($key, $removedKeys);
        })->sortBy(function ($value, $key) {
            return $key;
        })->toArray();

        return $settings;
    }

    public function saveJson(string $path): void
    {
        file_put_contents($path, json_encode($this->config, JSON_PRETTY_PRINT));
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
        return json_encode($this->config, JSON_PRETTY_PRINT);
    }

    public function toYaml(): string
    {
        return Yaml::dump($this->config, 6, 2);
    }
}
