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

    public function server()
    {
        if (!$this->task) {
            return null;
        }

        if ($this->task->application) {
            if ($this->task->application->destination && $this->task->application->destination->server) {
                return $this->task->application->destination->server;
            }
        } elseif ($this->task->service) {
            if ($this->task->service->destination && $this->task->service->destination->server) {
                return $this->task->service->destination->server;
            }
        }
        return null;
    }

    public function getServerTimezone()
    {
        $server = $this->server();
        if (!$server) {
            return 'UTC';
        }
        $serverTimezone = $server->settings->server_timezone;
        return $serverTimezone;
    }

    public function formatDateInServerTimezone($date)
    {
        $serverTimezone = $this->getServerTimezone();
        $dateObj = new \DateTime($date);
        try {
            $dateObj->setTimezone(new \DateTimeZone($serverTimezone));
        } catch (\Exception $e) {
            $dateObj->setTimezone(new \DateTimeZone('UTC'));
        }
        return $dateObj->format('Y-m-d H:i:s T');
    }
}
