<?php

namespace App\Http\Livewire\Project\Application;

use App\Jobs\ContainerStopJob;
use App\Models\Application;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class Deploy extends Component
{
    public string $applicationId;
    public $activity;
    public $status;
    public Application $application;
    public $destination;
    public array $parameters;

    protected string $deployment_uuid;
    protected array $command = [];
    protected $source;

    public function mount()
    {
        $this->parameters = get_parameters();
        $this->application = Application::where('id', $this->applicationId)->first();
        $this->destination = $this->application->destination->getMorphClass()::where('id', $this->application->destination->id)->first();
    }
    protected function set_deployment_uuid()
    {
        // Create Deployment ID
        $this->deployment_uuid = new Cuid2(7);
        $this->parameters['deployment_uuid'] = $this->deployment_uuid;
    }
    public function deploy(bool $force = false)
    {
        $this->set_deployment_uuid();

        queue_application_deployment(
            application_id: $this->application->id,
            deployment_uuid: $this->deployment_uuid,
            force_rebuild: $force,
        );
        return redirect()->route('project.application.deployments', [
            'project_uuid' => $this->parameters['project_uuid'],
            'application_uuid' => $this->parameters['application_uuid'],
            'environment_name' => $this->parameters['environment_name'],
        ]);
    }

    public function stop()
    {
        instant_remote_process(["docker rm -f {$this->application->uuid}"], $this->application->destination->server);
        $this->application->status = get_container_status(server: $this->application->destination->server, container_id: $this->application->uuid);
        $this->application->save();
    }
}
