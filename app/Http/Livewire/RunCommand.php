<?php

namespace App\Http\Livewire;

use Livewire\Component;

class RunCommand extends Component
{
    public $activity;

    public $isKeepAliveOn = false;

    public $manualKeepAlive = false;

    public $command = 'ls';

    public function render()
    {
        return view('livewire.run-command');
    }

    public function runCommand()
    {
        $this->isKeepAliveOn = true;

        $this->activity = coolifyProcess($this->command, 'testing-host');
    }

    public function runSleepingBeauty()
    {
        $this->isKeepAliveOn = true;

        $this->activity = coolifyProcess('x=1; while  [ $x -le 40 ]; do sleep 0.1 && echo "Welcome $x times" $(( x++ )); done', 'testing-host');
    }

    public function runDummyProjectBuild()
    {
        $this->isKeepAliveOn = true;

        $this->activity = coolifyProcess(<<<EOT
        cd projects/dummy-project
        ~/.docker/cli-plugins/docker-compose build --no-cache
        EOT, 'testing-host');
    }

    public function polling()
    {
        $this->activity?->refresh();

        if (data_get($this->activity, 'properties.exitCode') !== null) {
            $this->isKeepAliveOn = false;
        }
    }
}
