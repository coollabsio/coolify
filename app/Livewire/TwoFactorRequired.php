<?php

namespace App\Livewire;

use Livewire\Component;

class TwoFactorRequired extends Component
{
    public function mount()
    {
        if (filled(auth()->user()->two_factor_confirmed_at) && session('status') !== 'two-factor-authentication-confirmed') {
            return redirect()->route('dashboard');
        }
    }

    public function render()
    {
        return view('livewire.two-factor-required', [
            'teamName' => data_get(session('currentTeam'), 'name'),
        ])->layout('layouts.simple');
    }
}
