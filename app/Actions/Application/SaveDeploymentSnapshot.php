<?php

namespace App\Actions\Application;

use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

class SaveDeploymentSnapshot
{
    use AsAction;

    public function handle(
        Application $application,
        ApplicationDeploymentQueue $deployment,
        Server $server,
        string $dockerComposeContent,
        string $envContent,
        ?string $dockerfileContent = null,
        ?string $productionImageName = null,
        ?string $configHash = null
    ): bool {
        $deploymentDir = deployment_configuration_dir($application->uuid, $deployment->deployment_uuid);
        $deploymentsBaseDir = deployments_base_dir($application->uuid);
        $currentSymlink = current_deployment_symlink($application->uuid);
        $appBaseDir = application_configuration_dir()."/{$application->uuid}";

        // Create deployment directory
        instant_remote_process([
            "mkdir -p {$deploymentDir}",
        ], $server);

        // Save docker-compose.yaml
        $composeBase64 = base64_encode($dockerComposeContent);
        instant_remote_process([
            "echo '{$composeBase64}' | base64 -d | tee {$deploymentDir}/docker-compose.yaml > /dev/null",
        ], $server);

        // Save .env
        $envBase64 = base64_encode($envContent);
        instant_remote_process([
            "echo '{$envBase64}' | base64 -d | tee {$deploymentDir}/.env > /dev/null",
        ], $server);

        // Save Dockerfile if applicable
        if ($dockerfileContent) {
            $dockerfileBase64 = base64_encode($dockerfileContent);
            instant_remote_process([
                "echo '{$dockerfileBase64}' | base64 -d | tee {$deploymentDir}/Dockerfile > /dev/null",
            ], $server);
        }

        // Generate and save metadata
        $metadata = $this->generateMetadata($application, $deployment, $productionImageName, $configHash);
        $metadataJson = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $metadataBase64 = base64_encode($metadataJson);
        instant_remote_process([
            "echo '{$metadataBase64}' | base64 -d | tee {$deploymentDir}/metadata.json > /dev/null",
        ], $server);

        // Update current symlink to point to new deployment
        instant_remote_process([
            "ln -sfn {$deploymentDir} {$currentSymlink}",
        ], $server);

        // Create legacy symlinks for backward compatibility
        // These allow existing code that references the old paths to still work
        instant_remote_process([
            "ln -sf {$currentSymlink}/docker-compose.yaml {$appBaseDir}/docker-compose.yaml 2>/dev/null || true",
            "ln -sf {$currentSymlink}/.env {$appBaseDir}/.env 2>/dev/null || true",
        ], $server);

        return true;
    }

    private function generateMetadata(Application $application, ApplicationDeploymentQueue $deployment, ?string $productionImageName = null, ?string $configHash = null): array
    {
        // Use the actual production image name if provided (includes config hash for rollback support)
        // Fall back to commit-based name for backward compatibility with older deployments
        $imageName = $productionImageName ?? ($application->docker_registry_image_name
            ? "{$application->docker_registry_image_name}:{$deployment->commit}"
            : "{$application->uuid}:{$deployment->commit}");

        return [
            'version' => '1.0',
            'deployment_uuid' => $deployment->deployment_uuid,
            'application_uuid' => $application->uuid,
            'created_at' => now()->toIso8601String(),
            'commit' => $deployment->commit,
            'branch' => $application->git_branch,
            'build_pack' => $application->build_pack,
            'image_name' => $imageName,
            'config_hash' => $configHash,
            'pull_request_id' => $deployment->pull_request_id,
            'git_repository' => $application->git_repository,
            'configuration_snapshot' => [
                'fqdn' => $application->fqdn,
                'ports_exposes' => $application->ports_exposes,
                'ports_mappings' => $application->ports_mappings,
                'install_command' => $application->install_command,
                'build_command' => $application->build_command,
                'start_command' => $application->start_command,
                'dockerfile_location' => $application->dockerfile_location,
                'base_directory' => $application->base_directory,
                'publish_directory' => $application->publish_directory,
                'health_check_path' => $application->health_check_path,
                'health_check_port' => $application->health_check_port,
                'limits_memory' => $application->limits_memory,
                'limits_cpus' => $application->limits_cpus,
                // Additional fields for rebuild rollback support
                'static_image' => $application->static_image,
                'dockerfile' => $application->dockerfile,
                'dockerfile_target_build' => $application->dockerfile_target_build,
                'custom_docker_run_options' => $application->custom_docker_run_options,
                'custom_labels' => $application->custom_labels,
                'docker_compose_custom_build_command' => $application->docker_compose_custom_build_command,
                'docker_compose_custom_start_command' => $application->docker_compose_custom_start_command,
                'docker_compose_location' => $application->docker_compose_location,
            ],
            'settings_snapshot' => $this->serializeSettings($application),
            'build_environment_variables' => $this->serializeEnvironmentVariables($application, true),
            'runtime_environment_variables' => $this->serializeEnvironmentVariables($application, false),
        ];
    }

    private function serializeSettings(Application $application): array
    {
        $settings = $application->settings;
        if (! $settings) {
            return [];
        }

        return [
            'use_build_secrets' => $settings->use_build_secrets ?? false,
            'inject_build_args_to_dockerfile' => $settings->inject_build_args_to_dockerfile ?? false,
            'include_source_commit_in_build' => $settings->include_source_commit_in_build ?? false,
            'disable_build_cache' => $settings->disable_build_cache ?? false,
        ];
    }

    private function serializeEnvironmentVariables(Application $application, bool $buildTime): array
    {
        // Query based on the appropriate field:
        // - For build-time vars: use is_buildtime = true
        // - For runtime vars: use is_runtime = true
        // This is important because a variable can have both flags set (used in both contexts)
        if ($buildTime) {
            $envVars = $application->environment_variables()
                ->where('is_buildtime', true)
                ->get();
        } else {
            $envVars = $application->environment_variables()
                ->where('is_runtime', true)
                ->get();
        }

        return $envVars->map(function ($var) {
            return [
                'key' => $var->key,
                'value' => encrypt($var->real_value), // Encrypt value for secure storage on server
                'is_multiline' => $var->is_multiline ?? false,
                'is_literal' => $var->is_literal ?? false,
                'is_buildtime' => $var->is_buildtime ?? false,
                'is_runtime' => $var->is_runtime ?? true,
            ];
        })->toArray();
    }
}
