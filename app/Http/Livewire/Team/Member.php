<?php

namespace App\Http\Livewire\Team;

use App\Models\User;
use Livewire\Component;

class Member extends Component
{
    public User $member;

    public function makeAdmin()
    {
        $this->member->teams()->updateExistingPivot(auth()->user()->currentTeam()->id, ['role' => 'admin']);
        $this->emit('reloadWindow');
    }

    public function makeReadonly()
    {
        $this->member->teams()->updateExistingPivot(auth()->user()->currentTeam()->id, ['role' => 'member']);
        $this->emit('reloadWindow');
    }

    public function remove()
    {
        $this->member->teams()->detach(auth()->user()->currentTeam());
        $this->emit('reloadWindow');
    }
}
