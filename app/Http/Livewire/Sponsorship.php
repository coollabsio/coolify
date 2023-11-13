<?php

namespace App\Http\Livewire;

use Livewire\Component;

class Sponsorship extends Component
{
    public function disable()
    {
        auth()->user()->update(['is_notification_sponsorship_enabled' => false]);
    }
    public function render()
    {
        return view('livewire.sponsorship');
    }
}
