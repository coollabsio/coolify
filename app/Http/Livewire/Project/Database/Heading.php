<?php

namespace App\Http\Livewire\Project\Database;

use App\Actions\Database\StartPostgresql;
use App\Jobs\ContainerStatusJob;
use App\Notifications\Application\StatusChanged;
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
        dispatch_sync(new ContainerStatusJob(
            resource: $this->database,
            container_name: generate_container_name($this->database->uuid),
        ));
        $this->database->refresh();
    }

    public function mount()
    {
        $this->parameters = get_route_parameters();
    }

    public function stop()
    {
        remote_process(
            ["docker rm -f {$this->database->uuid}"],
            $this->database->destination->server
        );
        $this->database->status = 'stopped';
        $this->database->save();
        $this->database->environment->project->team->notify(new StatusChanged($this->database));
    }

    public function start()
    {
        if ($this->database->type() === 'standalone-postgresql') {
            $activity = resolve(StartPostgresql::class)($this->database->destination->server, $this->database);
            $this->emit('newMonitorActivity', $activity->id);
        }
    }
}
