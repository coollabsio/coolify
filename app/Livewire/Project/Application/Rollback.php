<?php

namespace App\Livewire\Project\Application;

use App\Actions\Application\GetRollbackImages;
use App\Models\Application;
use App\Traits\AuditsApplicationSettings;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Rollback extends Component
{
    use AuditsApplicationSettings;
    use AuthorizesRequests;

    public Application $application;

    public $images = [];

    public ?string $current = null;

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
            $this->saveApplicationSettingsWithAudit($this->application);
            $this->dispatch('success', 'Settings saved.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function rollbackImage($commit)
    {
        $this->authorize('deploy', $this->application);

        $commit = validateGitRef($commit, 'rollback commit');

        $deployment_uuid = new_public_id();

        $result = queue_application_deployment(
            application: $this->application,
            deployment_uuid: $deployment_uuid,
            commit: $commit,
            rollback: true,
            force_rebuild: false,
        );

        if ($result['status'] === 'queue_full') {
            $this->dispatch('error', 'Deployment queue full', $result['message']);

            return;
        }

        return redirectRoute($this, 'project.application.deployment.show', [
            'project_uuid' => $this->parameters['project_uuid'],
            'application_uuid' => $this->parameters['application_uuid'],
            'deployment_uuid' => $deployment_uuid,
            'environment_uuid' => $this->parameters['environment_uuid'],
        ]);
    }

    public function loadImages($showToast = false)
    {
        $this->authorize('view', $this->application);

        try {
            ['current' => $this->current, 'images' => $this->images] = GetRollbackImages::run($this->application);
            $showToast && $this->dispatch('success', 'Images loaded.');

            return [];
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
