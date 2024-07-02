<?php

namespace App\Livewire\Project\Database;

use App\Actions\Database\RestartDatabase;
use App\Actions\Database\StartDatabase;
use App\Actions\Database\StopDatabase;
use App\Actions\Docker\GetContainersStatus;
use Livewire\Component;

class Heading extends Component
{
    public $database;

    public array $parameters;

    public function getListeners()
    {
        $userId = auth()->user()->id;

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
        GetContainersStatus::run($this->database->destination->server);
        $this->database->refresh();
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
        StopDatabase::run($this->database);
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
}
