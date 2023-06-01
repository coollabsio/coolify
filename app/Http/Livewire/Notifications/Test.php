<?php

namespace App\Http\Livewire\Notifications;

use App\Models\Server;
use App\Models\Team;
use App\Notifications\TestNotification;
use Livewire\Component;
use Notification;

class Test extends Component
{
    public Team|Server $model;
    public function sendTestNotification()
    {
        Notification::send($this->model, new TestNotification);
        $this->emit('saved', 'Test notification sent.');
    }
}
