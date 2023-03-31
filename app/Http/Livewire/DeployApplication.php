<?php

namespace App\Http\Livewire;

use App\Jobs\ContainerStatusJob;
use App\Jobs\DeployApplicationJob;
use App\Models\Application;
use App\Models\CoolifyInstanceSettings;
use DateTimeImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Livewire\Component;
use Symfony\Component\Yaml\Yaml;
use Visus\Cuid2\Cuid2;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Builder;

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


    public function deploy()
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
        runRemoteCommandSync($this->destination->server, ["docker rm -f {$this->application_uuid} >/dev/null 2>&1"]);
        $this->application->status = 'exited';
        $this->application->save();
    }

    public function pollingStatus()
    {
        $this->application->refresh();
    }

    public function checkStatus()
    {
        $output = runRemoteCommandSync($this->destination->server, ["docker ps -a --format '{{.State}}' --filter 'name={$this->application->uuid}'"]);
        if ($output == '') {
            $this->application->status = 'exited';
            $this->application->save();
        } else {
            $this->application->status = $output;
            $this->application->save();
        }
    }
}
