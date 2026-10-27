<?php

namespace App\Livewire\Project\Application\Deployment;

use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\EnvironmentVariable;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Services\ImageScan\SecurityCard;
use App\Traits\AuditsApplicationSettings;
use Livewire\Component;
use Throwable;

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
        } catch (Throwable $e) {
            return handleError($e, $this);
        }
    }

    /**
     * Queue the blocked deployment again for the same commit, with the image scan gate bypassed.
     */
    public function deployAnyway()
    {
        $this->authorize('update', $this->application);

        try {
            $deployment = ApplicationDeploymentQueue::query()
                ->whereKey($this->application_deployment_queue->getKey())
                ->where('application_id', $this->application->id)
                ->firstOrFail();

            if (! $deployment->isImageScanBlocked()) {
                $this->dispatch('error', 'This deployment was not blocked.');

                return;
            }

            $latestDeploymentId = ApplicationDeploymentQueue::query()
                ->where('application_id', $this->application->id)
                ->where('pull_request_id', $deployment->pull_request_id)
                ->max('id');
            if ((int) $latestDeploymentId !== (int) $deployment->id) {
                $this->dispatch('error', 'A newer deployment exists.');

                return;
            }

            // The same server and destination as the blocked deployment, and the image it blocked.
            $server = $this->deploymentServer($deployment);
            $destination = $server === null ? null : StandaloneDocker::query()
                ->whereKey($deployment->destination_id)
                ->where('server_id', $server->id)
                ->first();
            $pinnedImage = data_get($deployment->scan_gate_result, 'repo_digest');

            $deploymentUuid = new_public_id();
            $result = queue_application_deployment(
                application: $this->application,
                deployment_uuid: $deploymentUuid,
                pull_request_id: $deployment->pull_request_id,
                commit: $deployment->commit,
                force_rebuild: false,
                git_type: $deployment->git_type,
                server: $server,
                destination: $destination,
                only_this_server: (bool) $deployment->only_this_server,
                docker_registry_image_tag: $deployment->docker_registry_image_tag,
                scan_gate_bypassed_by: auth()->id(),
                scan_gate_pinned_image: $this->application->build_pack === 'dockerimage' && is_string($pinnedImage) ? $pinnedImage : null,
            );
            if ($result['status'] !== 'queued') {
                $this->dispatch('error', 'Deploy did not start.', $result['message']);

                return;
            }

            $this->dispatch('success', 'Deploy started.');

            return redirectRoute($this, 'project.application.deployment.show', [
                'project_uuid' => $this->application->environment->project->uuid,
                'environment_uuid' => $this->application->environment->uuid,
                'application_uuid' => $this->application->uuid,
                'deployment_uuid' => $deploymentUuid,
            ]);
        } catch (Throwable $e) {
            return handleError($e, $this);
        }
    }

    /**
     * The server of a deployment when it is the destination server or an additional server of the application.
     */
    private function deploymentServer(ApplicationDeploymentQueue $deployment): ?Server
    {
        $servers = collect([$this->application->destination?->server])
            ->concat($this->application->additional_servers()->get())
            ->filter(fn ($server) => $server instanceof Server);

        return $servers->first(fn (Server $server) => (int) $server->id === (int) $deployment->server_id);
    }

    /**
     * Gate result of the shown deployment, for the log toolbar. Null when the gate passed or did not run. `action` is
     * the sentence of the action that fixes the blocked image, from the Security page.
     *
     * @return array{result: array<string, mixed>, blocked: bool, action: ?string}|null
     */
    public function getImageScanGateProperty(): ?array
    {
        $deployment = $this->application_deployment_queue;
        if (! $deployment->isImageScanBlocked() && ! $deployment->isImageScanSkipped()) {
            return null;
        }

        return [
            'result' => $deployment->scan_gate_result,
            'blocked' => $deployment->isImageScanBlocked(),
            'action' => $deployment->isImageScanBlocked() ? SecurityCard::gateAction($this->application, $deployment)?->sentence : null,
        ];
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
