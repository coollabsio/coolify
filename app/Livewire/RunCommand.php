<?php

namespace App\Livewire;

use App\Actions\Server\RunCommand as ServerRunCommand;
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

    protected $validationAttributes = [
        'server' => 'server',
        'command' => 'command',
    ];

    public function mount($servers)
    {
        $this->servers = $servers;
        $this->server = $servers[0]->uuid;
    }

    public function runCommand()
    {
        $this->validate();
        try {
            $activity = ServerRunCommand::run(server: Server::where('uuid', $this->server)->first(), command: $this->command);
            $this->dispatch('activityMonitor', $activity->id);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
