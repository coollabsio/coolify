<?php

namespace App\Livewire\Project\Database;

use App\Actions\Database\RestartDatabase;
use App\Actions\Database\StartDatabase;
use App\Actions\Database\StopDatabase;
use App\Actions\Docker\GetContainersStatus;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Heading extends Component
{
    public $database;

    public array $parameters;

    public $docker_cleanup = true;

    public function getListeners()
    {
        $userId = Auth::id();

        return [
            "echo-private:user.{$userId},DatabaseStatusChanged" => 'activityFinished',
        ];
    }

    public function activityFinished()
    {
        $this->database->update([
            'started_at' => now(),
        ]);
        $this->dispatch('refresh');
        $this->check_status();
        if (is_null($this->database->config_hash) || $this->database->isConfigurationChanged()) {
            $this->database->isConfigurationChanged(true);
            $this->dispatch('configurationChanged');
        } else {
            $this->dispatch('configurationChanged');
        }
    }

    public function check_status($showNotification = false)
    {
        if ($this->database->destination->server->isFunctional()) {
            GetContainersStatus::dispatch($this->database->destination->server);
        }

        if ($showNotification) {
            $this->dispatch('success', 'Database status updated.');
        }
    }

    public function mount()
    {
        $this->parameters = get_route_parameters();
    }

    public function stop()
    {
        StopDatabase::run($this->database, false, $this->docker_cleanup);
        $this->database->status = 'exited';
        $this->database->save();
        $this->check_status();
    }

    public function restart()
    {
        $activity = RestartDatabase::run($this->database);
        $this->dispatch('activityMonitor', $activity->id);
    }

    public function start()
    {
        $activity = StartDatabase::run($this->database);
        $this->dispatch('activityMonitor', $activity->id);
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
