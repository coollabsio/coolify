<?php

namespace App\Http\Livewire\Project\Application;

use App\Enums\ProcessStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use Livewire\Component;
use Spatie\Activitylog\Contracts\Activity;

class DeploymentCancel extends Component
{
    public Application $application;
    public $activity;
    public string $deployment_uuid;
    public function cancel()
    {
        try {
            ray('Cancelling deployment: ' . $this->deployment_uuid . 'of application: ' . $this->application->uuid);
            $deployment = ApplicationDeploymentQueue::where('deployment_uuid', $this->deployment_uuid)->firstOrFail();
            $deployment->status = 'cancelled';
            $deployment->save();
            $this->activity->properties = $this->activity->properties->merge([
                'exitCode' => 1,
                'status' =>  ProcessStatus::CANCELLED->value,
            ]);
            $this->activity->save();

            instant_remote_process(["docker rm -f {$this->deployment_uuid}"], $this->application->destination->server, throwError: false);
        } catch (\Throwable $th) {
            return general_error_handler($th, $this);
        }
    }
}
