<?php

namespace App\Livewire\Project\Application;

use App\Actions\Application\CleanupCancelledDeployment;
use App\Enums\ApplicationDeploymentStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use App\Traits\AuditsApplicationSettings;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class DeploymentNavbar extends Component
{
    use AuditsApplicationSettings, AuthorizesRequests;

    public ApplicationDeploymentQueue $application_deployment_queue;

    public Application $application;

    public Server $server;

    public bool $is_debug_enabled = false;

    protected $listeners = ['deploymentFinished'];

    public function mount()
    {
        $this->application = Application::ownedByCurrentTeam()->find($this->application_deployment_queue->application_id);
        $this->server = $this->application->destination->server;
        $this->is_debug_enabled = auth()->user()->isMember()
            ? false
            : $this->application->settings->is_debug_enabled;
    }

    public function deploymentFinished()
    {
        $this->application_deployment_queue->refresh();
    }

    public function show_debug()
    {
        try {
            $this->authorize('update', $this->application);
            $this->application->settings->is_debug_enabled = ! $this->application->settings->is_debug_enabled;
            $this->saveApplicationSettingsWithAudit($this->application);
            $this->is_debug_enabled = $this->application->settings->is_debug_enabled;
            $this->dispatch('refreshQueue');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function force_start()
    {
        try {
            $this->authorize('deploy', $this->application);
            $started = force_start_deployment($this->application_deployment_queue);
            $this->application_deployment_queue->refresh();

            if (! $started) {
                $this->dispatch('info', 'This deployment is no longer queued, so it was not started again.');
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function copyLogsToClipboard(): string
    {
        $this->authorize('view', $this->application);

        $logs = json_decode($this->application_deployment_queue->logs, associative: true, flags: JSON_THROW_ON_ERROR);

        if (! $logs) {
            return '';
        }

        $teamId = $this->application->team()?->id;
        $hideDebugLines = is_null($teamId) || ! auth()->user()->isAdminOfTeam($teamId);

        $markdown = "# Deployment Logs\n\n";
        $markdown .= "```\n";

        foreach ($logs as $log) {
            if ($hideDebugLines && ! empty($log['hidden'])) {
                continue;
            }
            if (isset($log['output'])) {
                $markdown .= $log['output']."\n";
            }
        }

        $markdown .= "```\n";

        return $markdown;
    }

    public function cancel()
    {
        try {
            $this->authorize('deploy', $this->application);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
        $teamId = $this->application->team()->id;
        $server_id = $this->application_deployment_queue->server_id ?? $this->application->destination->server_id;
        $server = Server::where('team_id', $teamId)->find($server_id);

        // First, mark the deployment as cancelled to prevent further processing
        $this->application_deployment_queue->update([
            'status' => ApplicationDeploymentStatus::CANCELLED_BY_USER->value,
        ]);
        try {
            $this->application_deployment_queue->addLogEntry('Deployment cancelled by user.', 'stderr');
            CleanupCancelledDeployment::run($this->application_deployment_queue, $teamId);
        } catch (\Throwable $e) {
            // Still mark as cancelled even if cleanup fails
            return handleError($e, $this);
        } finally {
            next_after_cancel($server, $this->application);
        }
    }
}
