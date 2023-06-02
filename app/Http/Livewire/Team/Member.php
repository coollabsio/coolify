<?php

namespace App\Http\Livewire\Team;

use App\Models\User;
use Livewire\Component;

class Member extends Component
{
    public User $member;
    public function render()
    {
        return view('livewire.team.member');
    }
}
