<?php

namespace App\Livewire\Subscription;

use Livewire\Component;

class Show extends Component
{
    public function mount()
    {
        if (! isCloud()) {
            return redirect()->route('dashboard');
        }
        if (auth()->user()?->isMember()) {
            return redirect()->route('dashboard');
        }
        if (! data_get(currentTeam(), 'subscription')) {
            return redirect()->route('subscription.index');
        }
    }

    public function render()
    {
        return view('livewire.subscription.show');
    }
}
