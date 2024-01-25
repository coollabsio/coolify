<?php

namespace App\Livewire\Project\Application;

use App\Enums\ApplicationDeploymentStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Livewire\Component;

class DeploymentNavbar extends Component
{
    public ApplicationDeploymentQueue $application_deployment_queue;
    public Application $application;
    public Server $server;
    public bool $is_debug_enabled = false;
    protected $listeners = ['deploymentFinished'];
    public function mount()
    {
        $this->application = Application::find($this->application_deployment_queue->application_id);
        $this->server = $this->application->destination->server;
        $this->is_debug_enabled = $this->application->settings->is_debug_enabled;
    }

    public function deploymentFinished()
    {
        $this->application_deployment_queue->refresh();
    }

    public function show_debug()
    {
        $this->application->settings->is_debug_enabled = !$this->application->settings->is_debug_enabled;
        $this->application->settings->save();
        $this->is_debug_enabled = $this->application->settings->is_debug_enabled;
        $this->dispatch('refreshQueue');
    }

    public function cancel()
    {
        try {
            $kill_command = "docker rm -f {$this->application_deployment_queue->deployment_uuid}";
            if ($this->application_deployment_queue->logs) {
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
                ]);
                instant_remote_process([$kill_command], $this->server);
            }
        } catch (\Throwable $e) {
            ray($e);
            return handleError($e, $this);
        } finally {
            $this->application_deployment_queue->update([
                'current_process_id' => null,
                'status' => ApplicationDeploymentStatus::CANCELLED_BY_USER->value,
            ]);
            // queue_next_deployment($this->application);
        }
    }
}
