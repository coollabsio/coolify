<?php

namespace App\Livewire\Project\Application\Deployment;

use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use Livewire\Component;

class Show extends Component
{
    public Application $application;

    public ApplicationDeploymentQueue $application_deployment_queue;

    public string $deployment_uuid;

    public string $horizon_job_status;

    public $isKeepAliveOn = true;

    public bool $is_debug_enabled = false;

    public bool $fullscreen = false;

    private bool $deploymentFinishedDispatched = false;

    public function getListeners()
    {
        return [
            'refreshQueue',
        ];
    }

    public function mount()
    {
        $deploymentUuid = request()->route('deployment_uuid');

        $project = currentTeam()->load(['projects'])->projects->where('uuid', request()->route('project_uuid'))->first();
        if (! $project) {
            return redirect()->route('dashboard');
        }
        $environment = $project->load(['environments'])->environments->where('uuid', request()->route('environment_uuid'))->first()->load(['applications']);
        if (! $environment) {
            return redirect()->route('dashboard');
        }
        $application = $environment->applications->where('uuid', request()->route('application_uuid'))->first();
        if (! $application) {
            return redirect()->route('dashboard');
        }
        $application_deployment_queue = ApplicationDeploymentQueue::where('deployment_uuid', $deploymentUuid)->first();
        if (! $application_deployment_queue) {
            return redirect()->route('project.application.deployment.index', [
                'project_uuid' => $project->uuid,
                'environment_uuid' => $environment->uuid,
                'application_uuid' => $application->uuid,
            ]);
        }
        $this->application = $application;
        $this->application_deployment_queue = $application_deployment_queue;
        $this->horizon_job_status = $this->application_deployment_queue->getHorizonJobStatus();
        $this->deployment_uuid = $deploymentUuid;
        $this->is_debug_enabled = $this->application->settings->is_debug_enabled;
        $this->isKeepAliveOn();
    }

    public function toggleDebug()
    {
        try {
            $this->authorize('update', $this->application);
            $this->application->settings->is_debug_enabled = ! $this->application->settings->is_debug_enabled;
            $this->application->settings->save();
            $this->is_debug_enabled = $this->application->settings->is_debug_enabled;
            $this->application_deployment_queue->refresh();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function refreshQueue()
    {
        $this->application_deployment_queue->refresh();
    }

    private function isKeepAliveOn()
    {
        if (data_get($this->application_deployment_queue, 'status') === 'finished' || data_get($this->application_deployment_queue, 'status') === 'failed') {
            $this->isKeepAliveOn = false;
        } else {
            $this->isKeepAliveOn = true;
        }
    }

    public function polling()
    {
        $this->application_deployment_queue->refresh();
        $this->horizon_job_status = $this->application_deployment_queue->getHorizonJobStatus();
        $this->isKeepAliveOn();

        // Dispatch event when deployment finishes to stop auto-scroll (only once)
        if (! $this->isKeepAliveOn && ! $this->deploymentFinishedDispatched) {
            $this->deploymentFinishedDispatched = true;
            $this->dispatch('deploymentFinished');
        }
    }

    public function getLogLinesProperty()
    {
        return decode_remote_command_output($this->application_deployment_queue);
    }

    public function copyLogs(): string
    {
        $logs = decode_remote_command_output($this->application_deployment_queue)
            ->map(function ($line) {
                return $line['timestamp'].' '.
                       (isset($line['command']) && $line['command'] ? '[CMD]: ' : '').
                       trim($line['line']);
            })
            ->join("\n");

        return sanitizeLogsForExport($logs);
    }

    public function render()
    {
        return view('livewire.project.application.deployment.show');
    }
}
