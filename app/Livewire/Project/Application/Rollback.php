<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class Rollback extends Component
{
    public Application $application;

    public $images = [];

    public ?string $current;

    public array $parameters;

    public function mount()
    {
        $this->parameters = get_route_parameters();
    }

    public function rollbackImage($commit)
    {
        $deployment_uuid = new Cuid2;

        queue_application_deployment(
            application: $this->application,
            deployment_uuid: $deployment_uuid,
            commit: $commit,
            rollback: true,
            force_rebuild: false,
        );

        return redirect()->route('project.application.deployment.show', [
            'project_uuid' => $this->parameters['project_uuid'],
            'application_uuid' => $this->parameters['application_uuid'],
            'deployment_uuid' => $deployment_uuid,
            'environment_uuid' => $this->parameters['environment_uuid'],
        ]);
    }

    public function loadImages($showToast = false)
    {
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
                    if ($item[1] === $this->current) {
                        // $is_current = true;
                    }

                    return [
                        'tag' => $item[1],
                        'created_at' => $item[2],
                        'is_current' => $is_current ?? null,
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
