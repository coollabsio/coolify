<?php

namespace App\Livewire\Team;

use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Invitations extends Component
{
    use AuthorizesRequests;

    public $invitations;

    protected $listeners = ['refreshInvitations'];

    public function deleteInvitation(int $invitation_id)
    {
        try {
            $this->authorize('manageInvitations', currentTeam());

            $invitation = TeamInvitation::ownedByCurrentTeam()->findOrFail($invitation_id);
            $user = User::whereEmail($invitation->email)->first();
            if (filled($user)) {
                $user->deleteIfNotVerifiedAndForcePasswordReset();
            }

            $invitation->delete();
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
