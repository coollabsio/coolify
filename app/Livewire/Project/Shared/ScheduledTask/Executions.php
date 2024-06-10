<?php

namespace App\Livewire\Project\Shared\ScheduledTask;

use Livewire\Component;

class Executions extends Component
{
    public $executions = [];

    public $selectedKey;

    public function getListeners()
    {
        return [
            'selectTask',
        ];
    }

    public function selectTask($key): void
    {
        if ($key == $this->selectedKey) {
            $this->selectedKey = null;

            return;
        }
        $this->selectedKey = $key;
    }
}
