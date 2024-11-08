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
        try {
            $initiation_found = TeamInvitation::ownedByCurrentTeam()->findOrFail($invitation_id);
            $initiation_found->delete();
            $this->refreshInvitations();
            $this->dispatch('success', 'Invitation revoked.');
        } catch (\Exception) {
            return $this->dispatch('error', 'Invitation not found.');
        }
    }

    public function refreshInvitations()
    {
        $this->invitations = TeamInvitation::ownedByCurrentTeam()->get();
    }
}
