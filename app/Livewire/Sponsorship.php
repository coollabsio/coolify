<?php

namespace App\Livewire;

use Livewire\Component;

class Sponsorship extends Component
{
    public function getListeners()
    {
        $userId = auth()->user()->id;
        return [
            "echo-private:custom.{$userId},TestEvent" => 'testEvent',
        ];
    }
    public function testEvent($asd)
    {
        $this->dispatch('success', 'Realtime events configured!');
    }
    public function disable()
    {
        auth()->user()->update(['is_notification_sponsorship_enabled' => false]);
    }
    public function render()
    {
        return view('livewire.sponsorship');
    }
}
