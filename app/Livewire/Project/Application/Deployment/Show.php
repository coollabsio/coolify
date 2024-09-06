<?php

namespace App\Livewire\Project\Application\Deployment;

use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use Illuminate\Support\Collection;
use Livewire\Component;

class Show extends Component
{
    public Application $application;

    public ApplicationDeploymentQueue $application_deployment_queue;

    public string $deployment_uuid;

    public $isKeepAliveOn = true;

    protected $listeners = ['refreshQueue'];

    public function mount()
    {
        $deploymentUuid = request()->route('deployment_uuid');

        $project = currentTeam()->load(['projects'])->projects->where('uuid', request()->route('project_uuid'))->first();
        if (! $project) {
            return redirect()->route('dashboard');
        }
        $environment = $project->load(['environments'])->environments->where('name', request()->route('environment_name'))->first()->load(['applications']);
        if (! $environment) {
            return redirect()->route('dashboard');
        }
        $application = $environment->applications->where('uuid', request()->route('application_uuid'))->first();
        if (! $application) {
            return redirect()->route('dashboard');
        }
        // $activity = Activity::where('properties->type_uuid', '=', $deploymentUuid)->first();
        // if (!$activity) {
        //     return redirect()->route('project.application.deployment.index', [
        //         'project_uuid' => $project->uuid,
        //         'environment_name' => $environment->name,
        //         'application_uuid' => $application->uuid,
        //     ]);
        // }
        $application_deployment_queue = ApplicationDeploymentQueue::where('deployment_uuid', $deploymentUuid)->first();
        if (! $application_deployment_queue) {
            return redirect()->route('project.application.deployment.index', [
                'project_uuid' => $project->uuid,
                'environment_name' => $environment->name,
                'application_uuid' => $application->uuid,
            ]);
        }
        $this->application = $application;
        $this->application_deployment_queue = $application_deployment_queue;
        $this->deployment_uuid = $deploymentUuid;
    }

    public function refreshQueue()
    {
        $this->application_deployment_queue->refresh();
    }

    public function polling()
    {
        $this->dispatch('deploymentFinished');
        $this->application_deployment_queue->refresh();
        if (data_get($this->application_deployment_queue, 'status') == 'finished' || data_get($this->application_deployment_queue, 'status') == 'failed') {
            $this->isKeepAliveOn = false;
        }
    }

    public function getLogLinesProperty()
    {
        return decode_remote_command_output($this->application_deployment_queue)->map(function ($logLine) {
            $logLine['line'] = e($logLine['line']);
            $logLine['line'] = preg_replace(
                '/(https?:\/\/[^\s]+)/',
                '<a href="$1" target="_blank" rel="noopener noreferrer" class="underline text-neutral-400">$1</a>',
                $logLine['line'],
            );

            return $logLine;
        });
    }

    public function render()
    {
        return view('livewire.project.application.deployment.show');
    }
}
