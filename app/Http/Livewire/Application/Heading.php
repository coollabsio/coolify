<?php

namespace App\Http\Livewire\Application;

use App\Jobs\ApplicationContainerStatusJob;
use App\Models\Application;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class Heading extends Component
{
    public Application $application;
    public array $parameters;

    protected string $deploymentUuid;

    public function mount()
    {
        $this->parameters = getRouteParameters();
    }

    public function check_status()
    {
        dispatch_sync(new ApplicationContainerStatusJob(
            application: $this->application,
            container_name: generate_container_name($this->application->uuid),
        ));
        $this->application->refresh();
    }
    public function deploy(bool $force_rebuild = false)
    {
        $this->setDeploymentUuid();
        queue_application_deployment(
            application_id: $this->application->id,
            deployment_uuid: $this->deploymentUuid,
            force_rebuild: $force_rebuild,
        );
        return redirect()->route('project.application.deployment', [
            'project_uuid' => $this->parameters['project_uuid'],
            'application_uuid' => $this->parameters['application_uuid'],
            'deployment_uuid' => $this->deploymentUuid,
            'environment_name' => $this->parameters['environment_name'],
        ]);
    }
    public function force_deploy_without_cache()
    {
        $this->deploy(force_rebuild: true);
    }
    public function stop()
    {
        remote_process(
            ["docker rm -f {$this->application->uuid}"],
            $this->application->destination->server
        );
        $this->application->status = 'stopped';
        $this->application->save();
    }
    protected function setDeploymentUuid()
    {
        $this->deploymentUuid = new Cuid2(7);
        $this->parameters['deployment_uuid'] = $this->deploymentUuid;
    }
}
