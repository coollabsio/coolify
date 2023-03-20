<?php

namespace App\Http\Livewire;

use Livewire\Component;

class RunCommand extends Component
{
    public $activity;

    public $isKeepAliveOn = false;

    public $manualKeepAlive = false;

    public $command = '';

    public function render()
    {
        return view('livewire.run-command');
    }

    public function runCommand()
    {
        // TODO Execute with Livewire Normally
        $this->activity = coolifyProcess($this->command, 'testing-host');


        // Override manual to experiment
//        $sleepingBeauty = 'x=1; while  [ $x -le 40 ]; do sleep 0.1 && echo "Welcome $x times" $(( x++ )); done';
//
//        $commandString = <<<EOT
//        cd projects/dummy-project
//        ~/.docker/cli-plugins/docker-compose build --no-cache
//        # $sleepingBeauty
//        EOT;
//
//        $this->activity = coolifyProcess($commandString, 'testing-host');


        $this->isKeepAliveOn = true;
    }

    public function polling()
    {
        $this->activity?->refresh();

        if ($this->activity?->properties['status'] === 'finished') {
            $this->isKeepAliveOn = false;
        }
    }
}
