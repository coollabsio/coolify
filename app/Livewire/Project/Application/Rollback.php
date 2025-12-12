<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class Rollback extends Component
{
    use AuthorizesRequests;

    public Application $application;

    public $images = [];

    public ?string $current;

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

    public function rollbackImage($commit)
    {
        $this->authorize('deploy', $this->application);

        $deployment_uuid = new Cuid2;

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

        return redirect()->route('project.application.deployment.show', [
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
            $image = $this->application->docker_registry_image_name ?? $this->application->uuid;
            if ($this->application->destination->server->isFunctional()) {
                $output = instant_remote_process([
                    "docker inspect --format='{{.Config.Image}}' {$this->application->uuid}",
                ], $this->application->destination->server, throwError: false);
                $current_tag = str($output)->trim()->explode(':');
                $this->current = data_get($current_tag, 1);

                $output = instant_remote_process([
                    "docker images --format '{{.Repository}}#{{.Tag}}#{{.CreatedAt}}'",
                ], $this->application->destination->server);
                $this->images = str($output)->trim()->explode("\n")->filter(function ($item) use ($image) {
                    return str($item)->contains($image);
                })->map(function ($item) {
                    $item = str($item)->explode('#');
                    $is_current = $item[1] === $this->current;

                    return [
                        'tag' => $item[1],
                        'created_at' => $item[2],
                        'is_current' => $is_current,
                    ];
                })->toArray();
            }
            $showToast && $this->dispatch('success', 'Images loaded.');

            return [];
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
