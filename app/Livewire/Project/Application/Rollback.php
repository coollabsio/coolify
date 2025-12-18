<?php

namespace App\Livewire\Project\Application;

use App\Enums\ApplicationDeploymentStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class Rollback extends Component
{
    use AuthorizesRequests;

    public Application $application;

    public array $deployments = [];

    public ?string $currentDeploymentUuid = null;

    public array $parameters;

    #[Validate(['integer', 'min:0', 'max:100'])]
    public int $dockerImagesToKeep = 2;

    public bool $serverRetentionDisabled = false;

    public function mount()
    {
        $this->parameters = get_route_parameters();
        $this->dockerImagesToKeep = $this->application->settings->docker_images_to_keep ?? 2;
        $server = $this->application->destination->server;
        $this->serverRetentionDisabled = $server->settings->disable_application_image_retention ?? false;
    }

    public function saveSettings()
    {
        try {
            $this->authorize('update', $this->application);
            $this->validate();
            $this->application->settings->docker_images_to_keep = $this->dockerImagesToKeep;
            $this->application->settings->save();
            $this->dispatch('success', 'Settings saved.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function loadDeployments($showToast = false)
    {
        $this->authorize('view', $this->application);

        try {
            // Get successful deployments from database (non-PR deployments)
            $dbDeployments = ApplicationDeploymentQueue::where('application_id', $this->application->id)
                ->where('status', ApplicationDeploymentStatus::FINISHED->value)
                ->where('pull_request_id', 0)
                ->orderBy('created_at', 'desc')
                ->limit(20)
                ->get();

            $server = $this->application->destination->server;

            if (! $server->isFunctional()) {
                $this->dispatch('error', 'Server is not functional.');

                return;
            }

            $deploymentsBaseDir = deployments_base_dir($this->application->uuid);

            // Check which deployments have valid configuration directories
            $existingDirs = instant_remote_process([
                "ls -1 {$deploymentsBaseDir} 2>/dev/null || true",
            ], $server, throwError: false);
            $existingDirsList = collect(explode("\n", trim($existingDirs)))->filter();

            // Get current deployment UUID from symlink
            $currentDeploymentOutput = instant_remote_process([
                "readlink {$deploymentsBaseDir}/../current 2>/dev/null | xargs -r basename || true",
            ], $server, throwError: false);
            $this->currentDeploymentUuid = trim($currentDeploymentOutput) ?: null;

            $this->deployments = $dbDeployments->map(function ($deployment) use ($existingDirsList, $server) {
                $hasConfig = $existingDirsList->contains($deployment->deployment_uuid);

                // Check if image exists
                $imageExists = false;
                if ($hasConfig) {
                    $imageName = $this->application->docker_registry_image_name
                        ? "{$this->application->docker_registry_image_name}:{$deployment->commit}"
                        : "{$this->application->uuid}:{$deployment->commit}";

                    $check = instant_remote_process([
                        "docker images -q {$imageName} 2>/dev/null | head -1",
                    ], $server, throwError: false);
                    $imageExists = ! empty(trim($check));
                }

                $isCurrent = $deployment->deployment_uuid === $this->currentDeploymentUuid;

                return [
                    'deployment_uuid' => $deployment->deployment_uuid,
                    'commit' => $deployment->commit,
                    'created_at' => $deployment->created_at,
                    'has_config' => $hasConfig,
                    'image_exists' => $imageExists,
                    'is_current' => $isCurrent,
                    'can_instant_rollback' => $hasConfig && $imageExists && ! $isCurrent,
                    'can_rebuild_rollback' => $hasConfig && ! $imageExists && ! $isCurrent,
                ];
            })->toArray();

            $showToast && $this->dispatch('success', 'Deployments loaded.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function rollbackToDeployment(string $deploymentUuid)
    {
        $this->authorize('deploy', $this->application);

        try {
            $server = $this->application->destination->server;

            if (! $server->isFunctional()) {
                $this->dispatch('error', 'Server is not functional.');

                return;
            }

            $deploymentDir = deployment_configuration_dir($this->application->uuid, $deploymentUuid);
            $currentSymlink = current_deployment_symlink($this->application->uuid);

            // Check if deployment directory exists
            $checkDir = instant_remote_process([
                "test -d {$deploymentDir} && echo 'exists' || echo 'not_found'",
            ], $server, throwError: false);

            if (trim($checkDir) !== 'exists') {
                $this->dispatch('error', 'Deployment configuration not found. Rebuild required.');
                $this->triggerRebuildRollback($deploymentUuid);

                return;
            }

            // Read metadata to get image name
            $metadataJson = instant_remote_process([
                "cat {$deploymentDir}/metadata.json 2>/dev/null || echo '{}'",
            ], $server, throwError: false);
            $metadata = json_decode($metadataJson, true) ?? [];

            $imageName = $metadata['image_name'] ?? "{$this->application->uuid}:{$metadata['commit']}";

            // Check if image exists
            $imageCheck = instant_remote_process([
                "docker images -q {$imageName} 2>/dev/null | head -1",
            ], $server, throwError: false);

            if (empty(trim($imageCheck))) {
                // Image doesn't exist - need to rebuild
                $this->dispatch('info', 'Image not found. Starting rebuild deployment...');
                $this->triggerRebuildRollback($deploymentUuid, $metadata['commit'] ?? 'HEAD');

                return;
            }

            // Image exists - perform instant rollback
            // Update current symlink
            instant_remote_process([
                "ln -sfn {$deploymentDir} {$currentSymlink}",
            ], $server);

            // Stop current containers and start from saved configuration
            instant_remote_process([
                "cd {$deploymentDir} && docker compose --project-name {$this->application->uuid} down --remove-orphans 2>/dev/null || true",
                "cd {$deploymentDir} && docker compose --project-name {$this->application->uuid} up -d",
            ], $server);

            $this->application->update(['status' => 'running']);

            $this->dispatch('success', 'Rollback completed successfully.');
            $this->loadDeployments();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    private function triggerRebuildRollback(string $deploymentUuid, string $commit = 'HEAD')
    {
        $newDeploymentUuid = new Cuid2;

        $result = queue_application_deployment(
            application: $this->application,
            deployment_uuid: $newDeploymentUuid,
            commit: $commit,
            rollback: true,
            force_rebuild: true,
        );

        if ($result['status'] === 'queue_full') {
            $this->dispatch('error', 'Deployment queue full', $result['message']);

            return;
        }

        if ($result['status'] === 'queued') {
            return redirectRoute($this, 'project.application.deployment.show', [
                'project_uuid' => $this->parameters['project_uuid'],
                'application_uuid' => $this->parameters['application_uuid'],
                'deployment_uuid' => $newDeploymentUuid,
                'environment_uuid' => $this->parameters['environment_uuid'],
            ]);
        }

        $this->dispatch('error', 'Failed to queue rollback deployment.');
    }

    public function render()
    {
        return view('livewire.project.application.rollback');
    }
}
