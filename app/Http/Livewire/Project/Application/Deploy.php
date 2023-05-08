<?php

namespace App\Http\Livewire\Project\Application;

use App\Jobs\DeployApplicationJob;
use App\Models\Application;
use Illuminate\Support\Facades\Route;
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
        $this->parameters = saveParameters();
        $this->application = Application::where('id', $this->applicationId)->first();
        $this->destination = $this->application->destination->getMorphClass()::where('id', $this->application->destination->id)->first();
    }
    protected function setDeploymentUuid()
    {
        // Create Deployment ID
        $this->deployment_uuid = new Cuid2(7);
        $this->parameters['deployment_uuid'] = $this->deployment_uuid;
    }
    protected function redirectToDeployment()
    {
        return redirect()->route('project.application.deployment', $this->parameters);
    }
    public function start()
    {
        $this->setDeploymentUuid();

        dispatch(new DeployApplicationJob(
            deployment_uuid: $this->deployment_uuid,
            application_uuid: $this->application->uuid,
            force_rebuild: false,
        ));

        return $this->redirectToDeployment();
    }
    public function forceRebuild()
    {
        $this->setDeploymentUuid();

        dispatch(new DeployApplicationJob(
            deployment_uuid: $this->deployment_uuid,
            application_uuid: $this->application->uuid,
            force_rebuild: true,
        ));

        return $this->redirectToDeployment();
    }

    public function delete()
    {
        $this->stop();
        Application::find($this->applicationId)->delete();
        return redirect()->route('project.resources', [
            'project_uuid' => $this->parameters['project_uuid'],
            'environment_name' => $this->parameters['environment_name']
        ]);
    }
    public function stop()
    {
        instantRemoteProcess(["docker rm -f {$this->application->uuid}"], $this->destination->server);
        if ($this->application->status != 'exited') {
            $this->application->status = 'exited';
            $this->application->save();
        }
    }

    public function pollingStatus()
    {
        $this->application->refresh();
    }
}
