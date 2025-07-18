<?php

namespace App\Livewire\Project\Database;

use App\Actions\Database\RestartDatabase;
use App\Actions\Database\StartDatabase;
use App\Actions\Database\StopDatabase;
use App\Actions\Docker\GetContainersStatus;
use App\Events\ServiceStatusChanged;
use Livewire\Component;

class Heading extends Component
{
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
        try {
            $this->database->started_at ??= now();
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

    public function mount()
    {
        $this->parameters = get_route_parameters();
    }

    public function stop()
    {
        try {
            $this->dispatch('info', 'Gracefully stopping database.');
            StopDatabase::dispatch($this->database, false, $this->docker_cleanup);
        } catch (\Exception $e) {
            $this->dispatch('error', $e->getMessage());
        }
    }

    public function restart()
    {
        $activity = RestartDatabase::run($this->database);
        $this->dispatch('activityMonitor', $activity->id, ServiceStatusChanged::class);
    }

    public function start()
    {
        $activity = StartDatabase::run($this->database);
        $this->dispatch('activityMonitor', $activity->id, ServiceStatusChanged::class);
    }

    public function render()
    {
        return view('livewire.project.database.heading', [
            'checkboxes' => [
                ['id' => 'docker_cleanup', 'label' => __('resource.docker_cleanup')],
            ],
        ]);
    }
}
