<?php

namespace App\Http\Livewire;

use App\Jobs\DeployApplicationJob;
use App\Models\Application;
use Illuminate\Support\Facades\Route;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class DeployApplication extends Component
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

    public function start()
    {
        // Create Deployment ID
        $this->deployment_uuid = new Cuid2(7);
        $this->parameters['deployment_uuid'] = $this->deployment_uuid;

        dispatch(new DeployApplicationJob(
            deployment_uuid: $this->deployment_uuid,
            application_uuid: $this->application->uuid,
        ));

        return redirect()->route('project.applications.deployment', $this->parameters);
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
