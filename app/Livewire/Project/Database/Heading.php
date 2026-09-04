<?php

namespace App\Livewire\Project\Database;

use App\Actions\Database\FlushCacheDatabase;
use App\Actions\Database\RestartDatabase;
use App\Actions\Database\StartDatabase;
use App\Actions\Database\StopDatabase;
use App\Actions\Docker\GetContainersStatus;
use App\Events\ServiceStatusChanged;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneRedis;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Heading extends Component
{
    use AuthorizesRequests;

    public $database;

    public array $parameters;

    public $docker_cleanup = true;

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
        if ($this->database->destination->server->isFunctional()) {
            GetContainersStatus::dispatch($this->database->destination->server);
        } else {
            $this->dispatch('error', 'Server is not functional.');
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

    public function flush()
    {
        try {
            $this->authorize('manage', $this->database);

            if (! $this->isCacheDatabase()) {
                throw new \RuntimeException('This database type does not support cache flushing.');
            }

            FlushCacheDatabase::run($this->database);
            $this->auditDatabaseAction('ui.database.flushed');
            $this->dispatch('success', 'Cache flushed successfully.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function restart()
    {
        try {
            $this->authorize('manage', $this->database);

            $activity = RestartDatabase::run($this->database);
            $this->auditDatabaseAction('ui.database.restarted');
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
            'isCacheDatabase' => $this->isCacheDatabase(),
        ]);
    }

    private function isCacheDatabase(): bool
    {
        return $this->database instanceof StandaloneRedis
            || $this->database instanceof StandaloneKeydb
            || $this->database instanceof StandaloneDragonfly;
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
