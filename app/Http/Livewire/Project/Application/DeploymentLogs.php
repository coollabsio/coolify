<?php

namespace App\Http\Livewire\Project\Application;

use App\Models\ApplicationDeploymentQueue;
use Livewire\Component;

class DeploymentLogs extends Component
{
    public ApplicationDeploymentQueue $application_deployment_queue;
    public $isKeepAliveOn = true;
    protected $listeners = ['refreshQueue'];

    public function refreshQueue()
    {
        $this->application_deployment_queue->refresh();
    }

    public function polling()
    {
        $this->emit('deploymentFinished');
        $this->application_deployment_queue->refresh();
        if (data_get($this->application_deployment_queue, 'status') == 'finished' || data_get($this->application_deployment_queue, 'status') == 'failed') {
            $this->isKeepAliveOn = false;
        }
    }
}
