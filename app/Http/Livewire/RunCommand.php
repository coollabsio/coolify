<?php

namespace App\Http\Livewire;

use App\Enums\ActivityTypes;
use App\Models\Server;
use Livewire\Component;

class RunCommand extends Component
{
    public $command;
    public $server;
    public $servers = [];

    protected $rules = [
        'server' => 'required',
        'command' => 'required',
    ];
    public function mount()
    {
        $this->servers = Server::where('team_id', session('currentTeam')->id)->get();
        $this->server = $this->servers[0]->uuid;
    }

    public function runCommand()
    {
        $this->validate();
        $activity = remoteProcess([$this->command], Server::where('uuid', $this->server)->first(), ActivityTypes::INLINE->value);
        $this->emit('newMonitorActivity', $activity->id);
    }
}
