<?php

namespace App\Livewire\Team;

use App\Models\TeamInvitation;
use Livewire\Component;

class Invitations extends Component
{
    public $invitations;

    protected $listeners = ['refreshInvitations'];

    public function deleteInvitation(int $invitation_id)
    {
        $initiation_found = TeamInvitation::find($invitation_id);
        if (! $initiation_found) {
            return $this->dispatch('error', 'Invitation not found.');
        }
        $initiation_found->delete();
        $this->refreshInvitations();
        $this->dispatch('success', 'Invitation revoked.');
    }

    public function refreshInvitations()
    {
        $this->invitations = TeamInvitation::whereTeamId(currentTeam()->id)->get();
    }
}
