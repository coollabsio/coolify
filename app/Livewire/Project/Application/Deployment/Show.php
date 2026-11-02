<?php

namespace App\Livewire\Project\Application\Deployment;

use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\EnvironmentVariable;
use App\Traits\AuditsApplicationSettings;
use Livewire\Component;

class Show extends Component
{
    use AuditsApplicationSettings;

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

    public function mount(string $project_uuid, string $environment_uuid, string $application_uuid, string $deployment_uuid)
    {
        $deploymentUuid = $deployment_uuid;

        $project = currentTeam()->load(['projects'])->projects->where('uuid', $project_uuid)->first();
        if (! $project) {
            return redirect()->route('dashboard');
        }
        $environment = $project->load(['environments'])->environments->where('uuid', $environment_uuid)->first();
        if (! $environment) {
            abort(404);
        }
        $environment->load(['applications']);
        $application = $environment->applications->where('uuid', $application_uuid)->first();
        if (! $application) {
            return redirect()->route('dashboard');
        }
        $application_deployment_queue = ApplicationDeploymentQueue::where('deployment_uuid', $deploymentUuid)
            ->where('application_id', $application->id)
            ->first();
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
        $this->is_debug_enabled = auth()->user()->isMember()
            ? false
            : $this->application->settings->is_debug_enabled;
        $this->isKeepAliveOn();
    }

    /**
     * Swap the logs viewer to another deployment of this application without a page load.
     */
    public function selectDeployment(string $deploymentUuid): void
    {
        $deployment = ApplicationDeploymentQueue::where('deployment_uuid', $deploymentUuid)
            ->where('application_id', $this->application->id)
            ->first();
        if (! $deployment) {
            return;
        }

        $this->application_deployment_queue = $deployment;
        $this->deployment_uuid = $deploymentUuid;
        $this->horizon_job_status = $deployment->getHorizonJobStatus();
        $this->isKeepAliveOn();

        $this->dispatch('deploymentSelected', deploymentUuid: $deploymentUuid)->to(Index::class);

        $url = route('project.application.deployment.show', [
            'project_uuid' => $this->application->environment->project->uuid,
            'environment_uuid' => $this->application->environment->uuid,
            'application_uuid' => $this->application->uuid,
            'deployment_uuid' => $deploymentUuid,
        ]);
        $this->js('history.replaceState(history.state, "", '.json_encode($url).')');
    }

    public function toggleDebug()
    {
        try {
            $this->authorize('update', $this->application);
            $this->application->settings->is_debug_enabled = ! $this->application->settings->is_debug_enabled;
            $this->saveApplicationSettingsWithAudit($this->application);
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

    /**
     * The displayed log lines that match the search, with secrets hidden.
     */
    public function exportLogs(bool $withTimestamps = true, string $search = ''): string
    {
        $this->authorize('view', $this->application);

        $search = mb_strtolower(trim($search));
        $lines = $this->logLines
            ->map(fn (array $line): array => [
                'timestamp' => $line['timestamp'],
                'text' => $this->exportLineText($line),
            ])
            ->filter(fn (array $line): bool => $search === '' || str_contains(mb_strtolower($line['timestamp'].' '.$line['text']), $search))
            ->map(fn (array $line): string => $withTimestamps ? $line['timestamp'].' '.$line['text'] : $line['text'])
            ->join("\n");

        return sanitizeLogsForExport($lines, $this->logRedactionSecrets());
    }

    /**
     * Every log line, also the debug lines, with secrets hidden.
     */
    public function downloadAllLogs(): string
    {
        $this->authorize('update', $this->application);

        $logs = decode_remote_command_output($this->application_deployment_queue, includeAll: true)
            ->map(fn (array $line): string => $line['timestamp'].' '.($line['hidden'] ? '[DEBUG] ' : '').$this->exportLineText($line))
            ->join("\n");

        return sanitizeLogsForExport($logs, $this->logRedactionSecrets());
    }

    /**
     * @param  array{line: string, command?: bool|null}  $line
     */
    private function exportLineText(array $line): string
    {
        return (! empty($line['command']) ? '[CMD]: ' : '').trim($line['line']);
    }

    /**
     * @return array{known: list<string>, locked: list<string>}
     */
    private function logRedactionSecrets(): array
    {
        return EnvironmentVariable::logRedactionSecretsFor(
            $this->application,
            includePreview: (int) $this->application_deployment_queue->pull_request_id !== 0,
        );
    }

    public function render()
    {
        return view('livewire.project.application.deployment.show');
    }
}
