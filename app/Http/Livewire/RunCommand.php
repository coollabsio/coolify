<?php

namespace App\Http\Livewire;

use App\Enums\ActivityTypes;
use App\Models\Server;
use Livewire\Component;

class RunCommand extends Component
{
    public string $command;
    public $server;
    public $servers = [];

    protected $rules = [
        'server' => 'required',
        'command' => 'required',
    ];
    public function mount($servers)
    {
        $this->servers = $servers;
        $this->server = $servers[0]->uuid;
    }

    public function runCommand()
    {
        try {
            $this->validate();
            $activity = remoteProcess([$this->command], Server::where('uuid', $this->server)->first(), ActivityTypes::INLINE->value);
            $this->emit('newMonitorActivity', $activity->id);
        } catch (\Exception $e) {
            return general_error_handler($e);
        }
    }
}
