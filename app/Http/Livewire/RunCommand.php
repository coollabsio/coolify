<?php

namespace App\Http\Livewire;

use App\Enums\ActivityTypes;
use App\Models\Server;
use Livewire\Component;

class RunCommand extends Component
{
    public $activity;

    public $isKeepAliveOn = false;

    public $manualKeepAlive = false;

    public $command = 'ls';

    public $server;

    public $servers = [];

    protected $rules = [
        'server' => 'required',
    ];
    public function mount()
    {
        $this->servers = Server::all();
        $this->server = $this->servers[0]->uuid;
    }

    public function runCommand()
    {
        $this->isKeepAliveOn = true;
        $this->activity = remoteProcess([$this->command], Server::where('uuid', $this->server)->first(), ActivityTypes::INLINE->value);
    }

    public function runSleepingBeauty()
    {
        $this->isKeepAliveOn = true;
        $this->activity = remoteProcess(['x=1; while  [ $x -le 40 ]; do sleep 0.1 && echo "Welcome $x times" $(( x++ )); done'], Server::where('uuid', $this->server)->first(), ActivityTypes::INLINE->value);
    }

    public function runDummyProjectBuild()
    {
        $this->isKeepAliveOn = true;
        $this->activity = remoteProcess([' cd projects/dummy-project', 'docker-compose build --no-cache'], Server::where('uuid', $this->server)->first(), ActivityTypes::INLINE->value);
    }

    public function polling()
    {
        $this->activity?->refresh();

        if (data_get($this->activity, 'properties.exitCode') !== null) {
            $this->isKeepAliveOn = false;
        }
    }
}
