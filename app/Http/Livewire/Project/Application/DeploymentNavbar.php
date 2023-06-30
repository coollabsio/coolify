<?php

namespace App\Http\Livewire\Project\Application;

use App\Enums\ApplicationDeploymentStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use Illuminate\Support\Carbon;
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
            $kill_command = "kill -9 {$this->application_deployment_queue->current_process_id}";
            $application = Application::find($this->application_deployment_queue->application_id);
            $server = $application->destination->server;
            if ($this->application_deployment_queue->current_process_id) {
                $process = Process::run("ps -p {$this->application_deployment_queue->current_process_id} -o command --no-headers");
                if (Str::of($process->output())->contains([$server->ip, 'EOF-COOLIFY-SSH'])) {
                    Process::run($kill_command);
                }
                $previous_logs = json_decode($this->application_deployment_queue->logs, associative: true, flags: JSON_THROW_ON_ERROR);
                $new_log_entry = [
                    'command' => $kill_command,
                    'output' => "Deployment cancelled by user.",
                    'type' => 'stderr',
                    'order' => count($previous_logs) + 1,
                    'timestamp' => Carbon::now('UTC'),
                    'hidden' => false,
                ];
                $previous_logs[] = $new_log_entry;
                $this->application_deployment_queue->update([
                    'logs' => json_encode($previous_logs, flags: JSON_THROW_ON_ERROR),
                    'current_process_id' => null,
                    'status' => ApplicationDeploymentStatus::CANCELLED_BY_USER->value,
                ]);
            }
        } catch (\Throwable $e) {
            return general_error_handler(err: $e, that: $this);
        }
    }
}
