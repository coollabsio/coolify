<?php

namespace App\Http\Livewire\Team;

use App\Models\User;
use Livewire\Component;

class Member extends Component
{
    public User $member;
    public function remove()
    {
        $this->member->teams()->detach(session('currentTeam'));
        session(['currentTeam' => session('currentTeam')->fresh()]);
        $this->emit('reloadWindow');
    }
}
