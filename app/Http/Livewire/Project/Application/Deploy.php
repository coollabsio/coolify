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
        $this->parameters = Route::current()->parameters();
        $this->application = Application::find($this->applicationId)->first();
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

    public function stop()
    {
        runRemoteCommandSync($this->destination->server, ["docker stop -t 0 {$this->application->uuid} >/dev/null 2>&1"]);
        $this->application->status = 'stopped';
        $this->application->save();
    }
    public function kill()
    {
        runRemoteCommandSync($this->destination->server, ["docker rm -f {$this->application->uuid}"]);
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
