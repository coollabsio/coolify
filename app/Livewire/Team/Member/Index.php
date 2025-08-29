<?php

namespace App\Livewire\Team\Member;

use App\Models\TeamInvitation;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Index extends Component
{
    use AuthorizesRequests;

    public $invitations = [];

    public function mount()
    {
        // Only load invitations for users who can manage them
        if (auth()->user()->can('manageInvitations', currentTeam())) {
            $this->invitations = TeamInvitation::whereTeamId(currentTeam()->id)->get();
        }
    }

    public function render()
    {
        return view('livewire.team.member.index');
    }
}
