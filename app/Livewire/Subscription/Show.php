<?php

namespace App\Livewire\Subscription;

use Livewire\Component;

class Show extends Component
{
    public function mount()
    {
        if (!isCloud()) {
            return redirect()->route('dashboard');
        }
    }
    public function render()
    {
        return view('livewire.subscription.show');
    }
}
