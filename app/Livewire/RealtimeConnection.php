<?php

namespace App\Livewire;

use Livewire\Component;

class RealtimeConnection extends Component
{
    public $checkConnection = false;
    public $showNotification = false;
    public $isNotificationEnabled = true;
    public function render()
    {
        return view('livewire.realtime-connection');
    }
    public function disable()
    {
        auth()->user()->update(['is_notification_realtime_enabled' => false]);
        $this->showNotification = false;
    }
    public function mount() {
        $this->isNotificationEnabled = auth()->user()->is_notification_realtime_enabled;
        $this->checkConnection = auth()->user()->id === 0;
    }
}
