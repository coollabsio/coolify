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
        ?string $productionImageName = null
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
        $metadata = $this->generateMetadata($application, $deployment, $productionImageName);
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

    private function generateMetadata(Application $application, ApplicationDeploymentQueue $deployment, ?string $productionImageName = null): array
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
            ],
        ];
    }
}
