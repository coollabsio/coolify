<?php

namespace App\Http\Livewire\Project\Application;

use App\Enums\ApplicationDeploymentStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use Illuminate\Support\Facades\Process;
use Livewire\Component;
use Illuminate\Support\Str;

class DeploymentNavbar extends Component
{
    protected $listeners = ['deploymentFinished'];

    public ApplicationDeploymentQueue $application_deployment_queue;

    public function deploymentFinished()
    {
        $this->application_deployment_queue->refresh();
    }
    public function show_debug()
    {
        $application = Application::find($this->application_deployment_queue->application_id);
        $application->settings->is_debug_enabled = !$application->settings->is_debug_enabled;
        $application->settings->save();
        $this->emit('refreshQueue');
    }
    public function cancel()
    {
        try {
            $application = Application::find($this->application_deployment_queue->application_id);
            $server = $application->destination->server;
            if ($this->application_deployment_queue->current_process_id) {
                $process = Process::run("ps -p {$this->application_deployment_queue->current_process_id} -o command --no-headers");
                if (Str::of($process->output())->contains([$server->ip, 'EOF-COOLIFY-SSH'])) {
                    Process::run("kill -9 {$this->application_deployment_queue->current_process_id}");
                }
                // TODO: Cancelling text in logs
                $this->application_deployment_queue->update([
                    'current_process_id' => null,
                    'status' => ApplicationDeploymentStatus::CANCELLED_BY_USER->value,
                ]);
            }
        } catch (\Throwable $e) {
            return general_error_handler(err: $e, that: $this);
        }
    }
}
