<?php

namespace App\Http\Livewire\Project\Database;

use App\Actions\Database\StartPostgresql;
use App\Jobs\ContainerStatusJob;
use Livewire\Component;

class Heading extends Component
{
    public $database;
    public array $parameters;

    protected $listeners = ['activityFinished'];

    public function activityFinished()
    {
        $this->database->update([
            'started_at' => now(),
        ]);
        $this->emit('refresh');
        $this->check_status();
    }

    public function check_status()
    {
        dispatch_sync(new ContainerStatusJob($this->database->destination->server));
        $this->database->refresh();
    }

    public function mount()
    {
        $this->parameters = get_route_parameters();
    }

    public function stop()
    {
        instant_remote_process(
            ["docker rm -f {$this->database->uuid}"],
            $this->database->destination->server
        );
        if ($this->database->is_public) {
            stopPostgresProxy($this->database);
            $this->database->is_public = false;
        }
        $this->database->status = 'stopped';
        $this->database->save();
        $this->check_status();
        // $this->database->environment->project->team->notify(new StatusChanged($this->database));
    }

    public function start()
    {
        if ($this->database->type() === 'standalone-postgresql') {
            $activity = resolve(StartPostgresql::class)($this->database->destination->server, $this->database);
            $this->emit('newMonitorActivity', $activity->id);
        }
    }
}
