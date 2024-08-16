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
        ray('Entering server() In UI');
        
        if (!$this->task) {
            ray('No task found, returning null');
            return null;
        }

        if ($this->task->application) {
            ray('Returning server from application');
            return $this->task->application->server;
        } elseif ($this->task->database) {
            ray('Returning server from database');
            return $this->task->database->server;
        } elseif ($this->task->service) {
            ray('Returning server from service');
            return $this->task->service->server;
        }
        
        ray('No server found, returning null');
        return null;
    }
    
    public function getServerTimezone()
    {
        $server = $this->server();
        if (!$server) {
            ray('No server found, returning default timezone');
            return 'UTC';
        }
        $serverTimezone = $server->settings->server_timezone ?? 'UTC';
        ray('Server Timezone:', $serverTimezone);
        return $serverTimezone;
    }

    public function formatDateInServerTimezone($date)
    {
        $serverTimezone = $this->getServerTimezone();
        $dateObj = new \DateTime($date);
        try {
            $dateObj->setTimezone(new \DateTimeZone($serverTimezone));
        } catch (\Exception $e) {
            ray('Invalid timezone:', $serverTimezone);
            // Fallback to UTC
            $dateObj->setTimezone(new \DateTimeZone('UTC'));
        }
        return $dateObj->format('Y-m-d H:i:s T');
    }
}