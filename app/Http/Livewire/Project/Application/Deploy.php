<?php

namespace App\Http\Livewire\Project\Application;

use App\Jobs\ApplicationContainerStatusJob;
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

    protected string $deploymentUuid;
    protected array $command = [];
    protected $source;

    protected $listeners = [
        'applicationStatusChanged',
    ];

    public function mount()
    {
        $this->parameters = getRouteParameters();
        $this->application = Application::where('id', $this->applicationId)->first();
        $this->destination = $this->application->destination->getMorphClass()::where('id', $this->application->destination->id)->first();
    }
    public function applicationStatusChanged()
    {
        $this->application->refresh();
    }
    protected function setDeploymentUuid()
    {
        $this->deploymentUuid = new Cuid2(7);
        $this->parameters['deployment_uuid'] = $this->deployment_uuid;
    }
    public function deploy(bool $force = false, bool|null $debug = null)
    {
        if ($debug && !$this->application->settings->is_debug_enabled) {
            $this->application->settings->is_debug_enabled = true;
            $this->application->settings->save();
        }
        $this->setDeploymentUuid();

        queue_application_deployment(
            application_id: $this->application->id,
            deployment_uuid: $this->deployment_uuid,
            force_rebuild: $force,
        );
        return redirect()->route('project.application.deployment', [
            'project_uuid' => $this->parameters['project_uuid'],
            'application_uuid' => $this->parameters['application_uuid'],
            'deployment_uuid' => $this->deployment_uuid,
            'environment_name' => $this->parameters['environment_name'],
        ]);
    }

    public function stop()
    {
        instant_remote_process(["docker rm -f {$this->application->uuid}"], $this->application->destination->server);
        $this->application->status = 'stopped';
        $this->application->save();
        $this->emit('applicationStatusChanged');
    }

    public function pollStatus()
    {
        dispatch(new ApplicationContainerStatusJob(
            application: $this->application,
            container_name: generate_container_name($this->application->uuid),
        ));
    }
}
