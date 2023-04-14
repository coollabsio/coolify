<?php

namespace App\Http\Livewire;

use App\Jobs\DeployApplicationJob;
use App\Models\Application;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class DeployApplication extends Component
{
    public string $application_uuid;
    public $activity;
    public $status;
    public Application $application;
    public $destination;

    protected string $deployment_uuid;
    protected array $command = [];
    protected $source;

    public function mount($application_uuid)
    {
        $this->application_uuid = $application_uuid;
        $this->application = Application::where('uuid', $this->application_uuid)->first();
        $this->destination = $this->application->destination->getMorphClass()::where('id', $this->application->destination->id)->first();
    }

    public function render()
    {
        return view('livewire.deploy-application');
    }


    public function start()
    {
        // Create Deployment ID
        $this->deployment_uuid = new Cuid2(7);

        dispatch(new DeployApplicationJob(
            deployment_uuid: $this->deployment_uuid,
            application_uuid: $this->application_uuid,
        ));

        $currentUrl = url()->previous();
        $deploymentUrl = "$currentUrl/deployment/$this->deployment_uuid";
        return redirect($deploymentUrl);
    }

    public function stop()
    {
        runRemoteCommandSync($this->destination->server, ["docker stop -t 0 {$this->application_uuid} >/dev/null 2>&1"]);
        $this->application->status = 'stopped';
        $this->application->save();
    }

    public function pollingStatus()
    {
        $this->application->refresh();
    }
}
