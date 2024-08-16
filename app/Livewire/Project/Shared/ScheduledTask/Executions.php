<?php

namespace App\Livewire\Project\Shared\ScheduledTask;

use Livewire\Component;

class Executions extends Component
{
    public $executions = [];
    public $selectedKey;
    public $task;

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

    public function getServerTimezone()
    {
        $server = data_get($this, 'destination.server');
        $serverTimezone = $server->settings->server_timezone;
        ray('Server Timezone:', $serverTimezone);
        return $serverTimezone;
    }
}