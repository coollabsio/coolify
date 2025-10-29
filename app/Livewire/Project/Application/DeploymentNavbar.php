<?php

namespace App\Livewire\Project\Application;

use App\Enums\ApplicationDeploymentStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use Illuminate\Support\Carbon;
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
        $this->application = Application::ownedByCurrentTeam()->find($this->application_deployment_queue->application_id);
        $this->server = $this->application->destination->server;
        $this->is_debug_enabled = $this->application->settings->is_debug_enabled;
    }

    public function deploymentFinished()
    {
        $this->application_deployment_queue->refresh();
    }

    public function show_debug()
    {
        $this->application->settings->is_debug_enabled = ! $this->application->settings->is_debug_enabled;
        $this->application->settings->save();
        $this->is_debug_enabled = $this->application->settings->is_debug_enabled;
        $this->dispatch('refreshQueue');
    }

    public function force_start()
    {
        try {
            force_start_deployment($this->application_deployment_queue);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function copyLogsToClipboard(): string
    {
        $logs = json_decode($this->application_deployment_queue->logs, associative: true, flags: JSON_THROW_ON_ERROR);

        if (! $logs) {
            return '';
        }

        $markdown = "# Deployment Logs\n\n";
        $markdown .= "```\n";

        foreach ($logs as $log) {
            if (isset($log['output'])) {
                $markdown .= $log['output']."\n";
            }
        }

        $markdown .= "```\n";

        return $markdown;
    }

    public function cancel()
    {
        $deployment_uuid = $this->application_deployment_queue->deployment_uuid;
        $kill_command = "docker rm -f {$deployment_uuid}";
        $build_server_id = $this->application_deployment_queue->build_server_id ?? $this->application->destination->server_id;
        $server_id = $this->application_deployment_queue->server_id ?? $this->application->destination->server_id;

        // First, mark the deployment as cancelled to prevent further processing
        $this->application_deployment_queue->update([
            'status' => ApplicationDeploymentStatus::CANCELLED_BY_USER->value,
        ]);

        try {
            if ($this->application->settings->is_build_server_enabled) {
                $server = Server::ownedByCurrentTeam()->find($build_server_id);
            } else {
                $server = Server::ownedByCurrentTeam()->find($server_id);
            }

            // Add cancellation log entry
            if ($this->application_deployment_queue->logs) {
                $previous_logs = json_decode($this->application_deployment_queue->logs, associative: true, flags: JSON_THROW_ON_ERROR);

                $new_log_entry = [
                    'command' => $kill_command,
                    'output' => 'Deployment cancelled by user.',
                    'type' => 'stderr',
                    'order' => count($previous_logs) + 1,
                    'timestamp' => Carbon::now('UTC'),
                    'hidden' => false,
                ];
                $previous_logs[] = $new_log_entry;
                $this->application_deployment_queue->update([
                    'logs' => json_encode($previous_logs, flags: JSON_THROW_ON_ERROR),
                ]);
            }

            // Try to stop the helper container if it exists
            // Check if container exists first
            $checkCommand = "docker ps -a --filter name={$deployment_uuid} --format '{{.Names}}'";
            $containerExists = instant_remote_process([$checkCommand], $server);

            if ($containerExists && str($containerExists)->trim()->isNotEmpty()) {
                // Container exists, kill it
                instant_remote_process([$kill_command], $server);
            } else {
                // Container hasn't started yet
                $this->application_deployment_queue->addLogEntry('Helper container not yet started. Deployment will be cancelled when job checks status.');
            }

            // Also try to kill any running process if we have a process ID
            if ($this->application_deployment_queue->current_process_id) {
                try {
                    $processKillCommand = "kill -9 {$this->application_deployment_queue->current_process_id}";
                    instant_remote_process([$processKillCommand], $server);
                } catch (\Throwable $e) {
                    // Process might already be gone, that's ok
                }
            }
        } catch (\Throwable $e) {
            // Still mark as cancelled even if cleanup fails
            return handleError($e, $this);
        } finally {
            $this->application_deployment_queue->update([
                'current_process_id' => null,
            ]);
            next_after_cancel($server);
        }
    }
}
