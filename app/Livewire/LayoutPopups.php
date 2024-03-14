<?php

namespace App\Livewire;

use Livewire\Component;

class LayoutPopups extends Component
{
    public function getListeners()
    {
        $teamId = auth()->user()->currentTeam()->id;
        return [
            "echo-private:team.{$teamId},TestEvent" => 'testEvent',
        ];
    }
    public function testEvent()
    {
        $this->dispatch('success', 'Realtime events configured!');
    }
    public function disableSponsorship()
    {
        auth()->user()->update(['is_notification_sponsorship_enabled' => false]);
    }
    public function disableNotifications()
    {
        auth()->user()->update(['is_notification_notifications_enabled' => false]);
    }
    public function render()
    {
        return view('livewire.layout-popups');
    }
}
