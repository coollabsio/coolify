<?php

namespace App\Livewire;

use App\Traits\ListensToTeamChannel;
use Livewire\Component;

class LayoutPopups extends Component
{
    use ListensToTeamChannel;

    public function getListeners()
    {
        return $this->teamChannelListeners([
            'TestEvent' => 'testEvent',
        ]);
    }

    public function testEvent()
    {
        $this->dispatch('success', 'Realtime events configured!');
    }

    public function render()
    {
        return view('livewire.layout-popups');
    }
}
