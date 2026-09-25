<?php

namespace App\Livewire\Project\Database;

use App\Actions\Database\RestartDatabase;
use App\Actions\Database\StartDatabase;
use App\Actions\Database\StopDatabase;
use App\Actions\Docker\GetContainersStatus;
use App\Enums\ProcessStatus;
use App\Events\ServiceStatusChanged;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Spatie\Activitylog\Models\Activity;

class Heading extends Component
{
    use AuthorizesRequests;

    public $database;

    public array $parameters;

    public $docker_cleanup = true;

    public $isDeploymentProgress = false;

    public $runningActivityId = null;

    public function getListeners()
    {
        $teamId = auth()->user()->currentTeam()->id;

        return [
            "echo-private:team.{$teamId},ServiceStatusChanged" => 'checkStatus',
            "echo-private:team.{$teamId},ServiceChecked" => 'activityFinished',
            'refresh' => '$refresh',
            'compose_loaded' => '$refresh',
            'update_links' => '$refresh',
        ];
    }

    public function activityFinished()
    {
        if (auth()->user()->cannot('update', $this->database)) {
            $this->dispatch('refresh');

            return;
        }

        try {
            // Only set started_at if database is actually running
            if ($this->database->isRunning()) {
                $this->database->started_at ??= now();
            }
            $this->database->save();

            if (is_null($this->database->config_hash) || $this->database->isConfigurationChanged()) {
                $this->database->isConfigurationChanged(true);
            }
            $this->dispatch('configurationChanged');
        } catch (\Exception $e) {
            return handleError($e, $this);
        } finally {
            $this->dispatch('refresh');
        }
    }

    public function checkStatus()
    {
        $this->checkDeployments();

        if ($this->database->destination->server->isFunctional()) {
            GetContainersStatus::dispatch($this->database->destination->server);
        } else {
            $this->dispatch('error', 'Server is not functional.');
        }
    }

    public function checkDeployments()
    {
        try {
            $activity = Activity::where('properties->type_uuid', $this->database->uuid)->latest()->first();
            $status = data_get($activity, 'properties.status');
            if ($status === ProcessStatus::QUEUED->value || $status === ProcessStatus::IN_PROGRESS->value) {
                $this->isDeploymentProgress = true;
                $this->runningActivityId = $activity->id;
            } else {
                $this->isDeploymentProgress = false;
                $this->runningActivityId = null;
            }
        } catch (\Throwable) {
            $this->isDeploymentProgress = false;
            $this->runningActivityId = null;
        }

        return $this->isDeploymentProgress;
    }

    /**
     * Re-attach the live log dialog to a start/restart that is already running,
     * so the log reappears after the dialog was closed.
     */
    public function reopenDeployment()
    {
        $this->authorize('view', $this->database);

        $this->checkDeployments();

        if ($this->isDeploymentProgress && $this->runningActivityId) {
            $this->dispatch('activityMonitor', $this->runningActivityId, ServiceStatusChanged::class);
            $this->js("window.dispatchEvent(new CustomEvent('startdatabase'))");
        } else {
            $this->dispatch('info', 'No operation is currently running.');
        }
    }

    private function markDeploymentRunning($activity): void
    {
        if (is_object($activity)) {
            $this->isDeploymentProgress = true;
            $this->runningActivityId = $activity->id;
        }
    }

    public function manualCheckStatus()
    {
        $this->checkStatus();
    }

    public function mount()
    {
        $this->parameters = [
            'project_uuid' => $this->database->environment->project->uuid,
            'environment_uuid' => $this->database->environment->uuid,
            'database_uuid' => $this->database->uuid,
        ];

        $this->checkDeployments();
    }

    public function stop()
    {
        try {
            $this->authorize('manage', $this->database);

            $this->dispatch('info', 'Gracefully stopping database.');
            StopDatabase::dispatch($this->database, false, $this->docker_cleanup);
            $this->auditDatabaseAction('ui.database.stopped');
        } catch (\Exception $e) {
            $this->dispatch('error', $e->getMessage());
        }
    }

    public function restart()
    {
        try {
            $this->authorize('manage', $this->database);

            $activity = RestartDatabase::run($this->database);
            $this->auditDatabaseAction('ui.database.restarted');
            $this->markDeploymentRunning($activity);
            $this->js("window.dispatchEvent(new CustomEvent('startdatabase'))");
            $this->dispatch('activityMonitor', $activity->id, ServiceStatusChanged::class);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function start()
    {
        try {
            $this->authorize('manage', $this->database);

            $activity = StartDatabase::run($this->database);
            $this->auditDatabaseAction('ui.database.started');
            $this->markDeploymentRunning($activity);
            $this->js("window.dispatchEvent(new CustomEvent('startdatabase'))");
            $this->dispatch('activityMonitor', $activity->id, ServiceStatusChanged::class);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.database.heading', [
            'checkboxes' => [
                ['id' => 'docker_cleanup', 'label' => __('resource.docker_cleanup')],
            ],
        ]);
    }

    private function auditDatabaseAction(string $event): void
    {
        auditLog($event, [
            'team_id' => $this->database->team()?->id,
            'database_uuid' => $this->database->uuid,
            'database_name' => $this->database->name,
        ]);
    }
}
