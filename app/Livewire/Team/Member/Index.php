<?php

namespace App\Livewire\Team\Member;

use App\Models\TeamInvitation;
use Livewire\Component;

class Index extends Component
{
    public $invitations = [];

    public function mount()
    {
        if (auth()->user()->isAdminFromSession()) {
            $this->invitations = TeamInvitation::whereTeamId(currentTeam()->id)->get();
        }
    }

    public function render()
    {
        return view('livewire.team.member.index');
    }
}
