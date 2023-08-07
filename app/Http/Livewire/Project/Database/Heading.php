<?php

namespace App\Http\Livewire\Project\Database;

use Livewire\Component;
use App\Actions\Database\StartPostgresql;

class Heading extends Component
{
    public $database;
    public array $parameters;

    public function mount()
    {
        $this->parameters = getRouteParameters();
    }
    public function start() {
        if ($this->database->type() === 'postgresql') {
            $activity = resolve(StartPostgresql::class)($this->database->destination->server, $this->database);
            $this->emit('newMonitorActivity', $activity->id);
        }
    }
}