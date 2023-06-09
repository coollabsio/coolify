<?php

namespace App\Http\Livewire\Team;

use App\Models\User;
use Livewire\Component;

class Member extends Component
{
    public User $member;
    public function makeAdmin()
    {
        $this->member->teams()->updateExistingPivot(session('currentTeam')->id, ['role' => 'admin']);
        $this->emit('reloadWindow');
    }
    public function makeReadonly()
    {
        $this->member->teams()->updateExistingPivot(session('currentTeam')->id, ['role' => 'readonly']);
        $this->emit('reloadWindow');
    }
    public function remove()
    {
        $this->member->teams()->detach(session('currentTeam'));
        $this->emit('reloadWindow');
    }
}
