<?php

namespace App\Livewire\Project\Application;

use App\Actions\Application\StopApplication;
use App\Events\ApplicationStatusChanged;
use App\Jobs\ContainerStatusJob;
use App\Jobs\ServerStatusJob;
use App\Models\Application;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class Heading extends Component
{
    public Application $application;
    public array $parameters;

    protected string $deploymentUuid;
    public function getListeners()
    {
        $teamId = auth()->user()->currentTeam()->id;
        return [
            "echo-private:team.{$teamId},ApplicationStatusChanged" => 'check_status',
        ];
    }
    public function mount()
    {
        $this->parameters = get_route_parameters();
    }

    public function check_status($showNotification = false)
    {
        if ($this->application->destination->server->isFunctional()) {
            dispatch(new ContainerStatusJob($this->application->destination->server));
            $this->application->refresh();
            $this->application->previews->each(function ($preview) {
                $preview->refresh();
            });
        } else {
            dispatch(new ServerStatusJob($this->application->destination->server));
        }
        if ($showNotification) $this->dispatch('success', "Application status updated.");
    }

    public function force_deploy_without_cache()
    {
        $this->deploy(force_rebuild: true);
    }

    public function deployNew()
    {
        if ($this->application->build_pack === 'dockercompose' && is_null($this->application->docker_compose_raw)) {
            $this->dispatch('error', 'Please load a Compose file first.');
            return;
        }
        $this->setDeploymentUuid();
        queue_application_deployment(
            application_id: $this->application->id,
            deployment_uuid: $this->deploymentUuid,
            force_rebuild: false,
            is_new_deployment: true,
        );
        return redirect()->route('project.application.deployment.show', [
            'project_uuid' => $this->parameters['project_uuid'],
            'application_uuid' => $this->parameters['application_uuid'],
            'deployment_uuid' => $this->deploymentUuid,
            'environment_name' => $this->parameters['environment_name'],
        ]);
    }
    public function deploy(bool $force_rebuild = false)
    {
        if ($this->application->build_pack === 'dockercompose' && is_null($this->application->docker_compose_raw)) {
            $this->dispatch('error', 'Please load a Compose file first.');
            return;
        }
        if ($this->application->destination->server->isSwarm() && is_null($this->application->docker_registry_image_name)) {
            $this->dispatch('error', 'To deploy to a Swarm cluster you must set a Docker image name first.');
            return;
        }
        if (data_get($this->application, 'settings.is_build_server_enabled') && is_null($this->application->docker_registry_image_name)) {
            $this->dispatch('error', 'To use a build server you must set a Docker image name first.<br>More information here: <a target="_blank" class="underline" href="https://coolify.io/docs/build-server">documentation</a>');
            return;
        }
        $this->setDeploymentUuid();
        queue_application_deployment(
            application_id: $this->application->id,
            deployment_uuid: $this->deploymentUuid,
            force_rebuild: $force_rebuild,
        );
        return redirect()->route('project.application.deployment.show', [
            'project_uuid' => $this->parameters['project_uuid'],
            'application_uuid' => $this->parameters['application_uuid'],
            'deployment_uuid' => $this->deploymentUuid,
            'environment_name' => $this->parameters['environment_name'],
        ]);
    }

    protected function setDeploymentUuid()
    {
        $this->deploymentUuid = new Cuid2(7);
        $this->parameters['deployment_uuid'] = $this->deploymentUuid;
    }

    public function stop()
    {
        StopApplication::run($this->application);
        $this->application->status = 'exited';
        $this->application->save();
        $this->application->refresh();
    }
    public function restartNew()
    {
        $this->setDeploymentUuid();
        queue_application_deployment(
            application_id: $this->application->id,
            deployment_uuid: $this->deploymentUuid,
            restart_only: true,
            is_new_deployment: true,
        );
        return redirect()->route('project.application.deployment.show', [
            'project_uuid' => $this->parameters['project_uuid'],
            'application_uuid' => $this->parameters['application_uuid'],
            'deployment_uuid' => $this->deploymentUuid,
            'environment_name' => $this->parameters['environment_name'],
        ]);
    }
    public function restart()
    {
        $this->setDeploymentUuid();
        queue_application_deployment(
            application_id: $this->application->id,
            deployment_uuid: $this->deploymentUuid,
            restart_only: true,
        );
        return redirect()->route('project.application.deployment.show', [
            'project_uuid' => $this->parameters['project_uuid'],
            'application_uuid' => $this->parameters['application_uuid'],
            'deployment_uuid' => $this->deploymentUuid,
            'environment_name' => $this->parameters['environment_name'],
        ]);
    }
}
