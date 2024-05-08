<?php

namespace App\Livewire\Project\Database;

use App\Actions\Database\StartClickhouse;
use App\Actions\Database\StartDragonfly;
use App\Actions\Database\StartKeydb;
use App\Actions\Database\StartMariadb;
use App\Actions\Database\StartMongodb;
use App\Actions\Database\StartMysql;
use App\Actions\Database\StartPostgresql;
use App\Actions\Database\StartRedis;
use App\Actions\Database\StopDatabase;
use App\Actions\Docker\GetContainersStatus;
use App\Jobs\ContainerStatusJob;
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
        // dispatch_sync(new ContainerStatusJob($this->database->destination->server));
        $this->database->refresh();
        if ($showNotification) $this->dispatch('success', 'Database status updated.');
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

    public function start()
    {
        if ($this->database->type() === 'standalone-postgresql') {
            $activity = StartPostgresql::run($this->database);
            $this->dispatch('activityMonitor', $activity->id);
        } else if ($this->database->type() === 'standalone-redis') {
            $activity = StartRedis::run($this->database);
            $this->dispatch('activityMonitor', $activity->id);
        } else if ($this->database->type() === 'standalone-mongodb') {
            $activity = StartMongodb::run($this->database);
            $this->dispatch('activityMonitor', $activity->id);
        } else if ($this->database->type() === 'standalone-mysql') {
            $activity = StartMysql::run($this->database);
            $this->dispatch('activityMonitor', $activity->id);
        } else if ($this->database->type() === 'standalone-mariadb') {
            $activity = StartMariadb::run($this->database);
            $this->dispatch('activityMonitor', $activity->id);
        } else if ($this->database->type() === 'standalone-keydb') {
            $activity = StartKeydb::run($this->database);
            $this->dispatch('activityMonitor', $activity->id);
        } else if ($this->database->type() === 'standalone-dragonfly') {
            $activity = StartDragonfly::run($this->database);
            $this->dispatch('activityMonitor', $activity->id);
        } else if ($this->database->type() === 'standalone-clickhouse') {
            $activity = StartClickhouse::run($this->database);
            $this->dispatch('activityMonitor', $activity->id);
        }
    }
}
