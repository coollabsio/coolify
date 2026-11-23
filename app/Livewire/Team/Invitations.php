<?php

namespace App\Livewire\Team;

use App\Enums\Role;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Invitations extends Component
{
    use AuthorizesRequests;

    public $invitations;

    /** Sort value such as `email-asc`; null keeps the loaded order. */
    #[Locked]
    public ?string $sort = null;

    protected $listeners = ['refreshInvitations'];

    public function deleteInvitation(int $invitation_id)
    {
        try {
            $this->authorize('manageInvitations', currentTeam());

            $invitation = TeamInvitation::ownedByCurrentTeam()->findOrFail($invitation_id);
            $invitationEmail = $invitation->email;
            $invitationUuid = $invitation->uuid;
            DB::transaction(function () use ($invitation): void {
                $invitation->delete();
                $user = User::whereEmail($invitation->email)->first();
                if (filled($user)) {
                    $user->deleteIfNotVerifiedAndForcePasswordReset();
                }
            });
            auditLog('ui.team_invitation.revoked', [
                'team_id' => currentTeam()->id,
                'invitation_uuid' => $invitationUuid,
                'invitation_email' => $invitationEmail,
            ]);
            $this->refreshInvitations();
            $this->dispatch('success', 'Invitation revoked.');
        } catch (\Exception) {
            return $this->dispatch('error', 'Invitation not found.');
        }
    }

    public function setSort(string $sort): void
    {
        if (! in_array($sort, ['email-asc', 'email-desc', 'via-asc', 'via-desc', 'role-asc', 'role-desc'], true)) {
            return;
        }

        $this->sort = $sort;
    }

    public function render()
    {
        return view('livewire.team.invitations', [
            'sortedInvitations' => $this->sortedInvitations(),
        ]);
    }

    private function sortedInvitations(): Collection
    {
        $invitations = collect($this->invitations);
        if ($this->sort === null) {
            return $invitations;
        }

        [$column, $direction] = explode('-', $this->sort, 2);
        $sortValue = fn (TeamInvitation $invitation): int|string => $column === 'role'
            ? (Role::tryFrom((string) $invitation->role)?->rank() ?? 0)
            : strtolower((string) $invitation->{$column});

        $order = $direction === 'desc' ? -1 : 1;

        return $invitations
            ->sort(fn (TeamInvitation $left, TeamInvitation $right): int => ($order * ($sortValue($left) <=> $sortValue($right)))
                ?: strcasecmp((string) $left->email, (string) $right->email))
            ->values();
    }

    public function refreshInvitations()
    {
        $this->invitations = TeamInvitation::ownedByCurrentTeam()->get();
    }
}
