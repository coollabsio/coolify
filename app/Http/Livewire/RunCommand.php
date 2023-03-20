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

        // Override manual to experiment
        $override = 0;

        if($override) {
            // Good to play with the throttle feature
            $sleepingBeauty = 'x=1; while  [ $x -le 40 ]; do sleep 0.1 && echo "Welcome $x times" $(( x++ )); done';

            $this->activity = coolifyProcess(<<<EOT
            cd projects/dummy-project
            # ~/.docker/cli-plugins/docker-compose build --no-cache
            $sleepingBeauty
            EOT, 'testing-host');

            return;
        }

        $this->activity = coolifyProcess($this->command, 'testing-host');
    }

    public function polling()
    {
        $this->activity?->refresh();

        if (data_get($this->activity, 'properties.exitCode') !== null) {
            $this->isKeepAliveOn = false;
        }
    }
}
