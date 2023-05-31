<?php

namespace App\Http\Livewire\Project\Application;

use App\Enums\ProcessStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use Livewire\Component;

class DeploymentNavbar extends Component
{
    public Application $application;
    public $activity;
    public string $deployment_uuid;
    public function cancel()
    {
        try {
            ray('Cancelling deployment: ' . $this->deployment_uuid . ' of application: ' . $this->application->uuid);

            // Update deployment queue
            $deployment = ApplicationDeploymentQueue::where('deployment_uuid', $this->deployment_uuid)->first();
            $deployment->status = 'cancelled by user';
            $deployment->save();

            // Update activity
            $this->activity->properties = $this->activity->properties->merge([
                'exitCode' => 1,
                'status' =>  ProcessStatus::CANCELLED->value,
            ]);
            $this->activity->save();

            // Remove builder container
            instant_remote_process(["docker rm -f {$this->deployment_uuid}"], $this->application->destination->server, throwError: false, repeat: 25);
            queue_next_deployment($this->application);
        } catch (\Throwable $th) {
            return general_error_handler($th, $this);
        }
    }
}
