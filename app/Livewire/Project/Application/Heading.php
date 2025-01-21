<?php

namespace App\Livewire\Project\Application;

use App\Actions\Application\StopApplication;
use App\Actions\Docker\GetContainersStatus;
use App\Events\ApplicationStatusChanged;
use App\Models\Application;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class Heading extends Component
{
    public Application $application;

    public ?string $lastDeploymentInfo = null;

    public ?string $lastDeploymentLink = null;

    public array $parameters;

    protected string $deploymentUuid;

    public bool $docker_cleanup = true;

    public function getListeners()
    {
        $teamId = auth()->user()->currentTeam()->id;

        return [
            "echo-private:team.{$teamId},ApplicationStatusChanged" => 'check_status',
            'compose_loaded' => '$refresh',
            'update_links' => '$refresh',
        ];
    }

    public function mount()
    {
        $this->parameters = [
            'project_uuid' => $this->application->project()->uuid,
            'environment_uuid' => $this->application->environment->uuid,
            'application_uuid' => $this->application->uuid,
        ];
        $lastDeployment = $this->application->get_last_successful_deployment();
        $this->lastDeploymentInfo = data_get_str($lastDeployment, 'commit')->limit(7).' '.data_get($lastDeployment, 'commit_message');
        $this->lastDeploymentLink = $this->application->gitCommitLink(data_get($lastDeployment, 'commit'));
    }

    public function check_status($showNotification = false)
    {
        if ($this->application->destination->server->isFunctional()) {
            GetContainersStatus::dispatch($this->application->destination->server);
        }
        if ($showNotification) {
            $this->dispatch('success', 'Success', 'Application status updated.');
        }
    }

    public function force_deploy_without_cache()
    {
        $this->deploy(force_rebuild: true);
    }

    public function deploy(bool $force_rebuild = false)
    {
        if ($this->application->build_pack === 'dockercompose' && is_null($this->application->docker_compose_raw)) {
            $this->dispatch('error', 'Failed to deploy', 'Please load a Compose file first.');

            return;
        }
        if ($this->application->destination->server->isSwarm() && str($this->application->docker_registry_image_name)->isEmpty()) {
            $this->dispatch('error', 'Failed to deploy.', 'To deploy to a Swarm cluster you must set a Docker image name first.');

            return;
        }
        if (data_get($this->application, 'settings.is_build_server_enabled') && str($this->application->docker_registry_image_name)->isEmpty()) {
            $this->dispatch('error', 'Failed to deploy.', 'To use a build server, you must first set a Docker image.<br>More information here: <a target="_blank" class="underline" href="https://coolify.io/docs/knowledge-base/server/build-server">documentation</a>');

            return;
        }
        if ($this->application->additional_servers->count() > 0 && str($this->application->docker_registry_image_name)->isEmpty()) {
            $this->dispatch('error', 'Failed to deploy.', 'Before deploying to multiple servers, you must first set a Docker image in the General tab.<br>More information here: <a target="_blank" class="underline" href="https://coolify.io/docs/knowledge-base/server/multiple-servers">documentation</a>');

            return;
        }
        $this->setDeploymentUuid();
        queue_application_deployment(
            application: $this->application,
            deployment_uuid: $this->deploymentUuid,
            force_rebuild: $force_rebuild,
        );

        return $this->redirectRoute('project.application.deployment.show', [
            'project_uuid' => $this->parameters['project_uuid'],
            'application_uuid' => $this->parameters['application_uuid'],
            'deployment_uuid' => $this->deploymentUuid,
            'environment_uuid' => $this->parameters['environment_uuid'],
        ], navigate: true);
    }

    protected function setDeploymentUuid()
    {
        $this->deploymentUuid = new Cuid2;
        $this->parameters['deployment_uuid'] = $this->deploymentUuid;
    }

    public function stop()
    {
        StopApplication::run($this->application, false, $this->docker_cleanup);
        $this->application->status = 'exited';
        $this->application->save();
        if ($this->application->additional_servers->count() > 0) {
            $this->application->additional_servers->each(function ($server) {
                $server->pivot->status = 'exited:unhealthy';
                $server->pivot->save();
            });
        }
        ApplicationStatusChanged::dispatch(data_get($this->application, 'environment.project.team.id'));
    }

    public function restart()
    {
        if ($this->application->additional_servers->count() > 0 && str($this->application->docker_registry_image_name)->isEmpty()) {
            $this->dispatch('error', 'Failed to deploy', 'Before deploying to multiple servers, you must first set a Docker image in the General tab.<br>More information here: <a target="_blank" class="underline" href="https://coolify.io/docs/knowledge-base/server/multiple-servers">documentation</a>');

            return;
        }
        $this->setDeploymentUuid();
        queue_application_deployment(
            application: $this->application,
            deployment_uuid: $this->deploymentUuid,
            restart_only: true,
        );

        return $this->redirectRoute('project.application.deployment.show', [
            'project_uuid' => $this->parameters['project_uuid'],
            'application_uuid' => $this->parameters['application_uuid'],
            'deployment_uuid' => $this->deploymentUuid,
            'environment_uuid' => $this->parameters['environment_uuid'],
        ], navigate: true);
    }

    public function render()
    {
        return view('livewire.project.application.heading', [
            'checkboxes' => [
                ['id' => 'docker_cleanup', 'label' => __('resource.docker_cleanup')],
            ],
        ]);
    }
}
