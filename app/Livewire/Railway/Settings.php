<?php

namespace App\Livewire\Railway;

use App\Actions\Team\InviteToTeam;
use App\Actions\User\RevokeUserTeamTokens;
use App\Enums\Role;
use App\Livewire\Railway\Concerns\LoadsProjectContext;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Support\RailwayResourceMapper;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.railway')]
class Settings extends Component
{
    use LoadsProjectContext;

    public string $section = 'general';

    public string $projectName = '';

    public ?string $projectDescription = null;

    public string $inviteEmail = '';

    public string $inviteRole = 'member';

    public ?string $generatedInviteLink = null;

    public function mount(string $project_uuid, string $environment_uuid): void
    {
        $this->loadProjectContext($project_uuid, $environment_uuid);
        $this->projectName = $this->project->name;
        $this->projectDescription = $this->project->description;
        $this->inviteEmail = isDev() ? 'test3@example.com' : '';
    }

    public function setSection(string $section): void
    {
        $this->section = $section;
    }

    /**
     * Invite a new member to the current team (Coolify has no per-project membership,
     * so "add a member" invites them to the whole team).
     */
    public function inviteMember(bool $sendEmail = false): void
    {
        $this->validate([
            'inviteEmail' => 'required|email',
            'inviteRole' => 'required|in:member,admin,owner',
        ]);

        try {
            $this->authorize('manageInvitations', currentTeam());

            $result = app(InviteToTeam::class)->handle(
                team: currentTeam(),
                inviter: auth()->user(),
                email: $this->inviteEmail,
                role: $this->inviteRole,
                sendEmail: $sendEmail,
            );

            $this->reset('inviteEmail', 'inviteRole');
            if ($sendEmail) {
                $this->generatedInviteLink = null;
                $this->dispatch('success', 'Invitation sent via email.');
            } else {
                $this->generatedInviteLink = $result['link'];
                $this->dispatch('success', 'Invitation link generated.');
            }
        } catch (\Throwable $e) {
            $this->dispatch('error', $e->getMessage());
        }
    }

    public function revokeInvitation(int $invitationId): void
    {
        try {
            $this->authorize('manageInvitations', currentTeam());

            $invitation = TeamInvitation::ownedByCurrentTeam()->findOrFail($invitationId);
            $user = User::whereEmail($invitation->email)->first();
            if (filled($user)) {
                $user->deleteIfNotVerifiedAndForcePasswordReset();
            }
            $invitation->delete();
            $this->dispatch('success', 'Invitation revoked.');
        } catch (\Throwable) {
            $this->dispatch('error', 'Invitation not found.');
        }
    }

    public function changeRole(int $userId, string $role): void
    {
        try {
            $this->authorize('manageMembers', currentTeam());

            if (! in_array($role, ['member', 'admin', 'owner'], true)) {
                throw new \Exception('Invalid role.');
            }
            // Only an owner can grant ownership.
            if ($role === 'owner' && Role::from(auth()->user()->role())->lt(Role::OWNER)) {
                throw new \Exception('Only an owner can grant ownership.');
            }
            $member = $this->teamMember($userId);
            // Cannot act on a member whose current rank exceeds yours.
            if (Role::from(auth()->user()->role())->lt(Role::ADMIN)
                || Role::from($this->memberRole($member))->gt(auth()->user()->role())) {
                throw new \Exception('You are not authorized to perform this action.');
            }
            $teamId = currentTeam()->id;
            $member->teams()->updateExistingPivot($teamId, ['role' => $role]);
            RevokeUserTeamTokens::forUserTeam($member, $teamId);
            $this->dispatch('success', 'Member role updated.');
        } catch (\Throwable $e) {
            $this->dispatch('error', $e->getMessage());
        }
    }

    public function removeMember(int $userId): void
    {
        try {
            $this->authorize('manageMembers', currentTeam());

            $member = $this->teamMember($userId);
            if (Role::from(auth()->user()->role())->lt(Role::ADMIN)
                || Role::from($this->memberRole($member))->gt(auth()->user()->role())) {
                throw new \Exception('You are not authorized to perform this action.');
            }
            $teamId = currentTeam()->id;
            $member->teams()->detach(currentTeam());
            RevokeUserTeamTokens::forUserTeam($member, $teamId);
            Cache::forget("team:{$member->id}");
            Cache::forget("user:{$member->id}:team:{$teamId}");
            $this->dispatch('success', 'Member removed.');
        } catch (\Throwable $e) {
            $this->dispatch('error', $e->getMessage());
        }
    }

    private function teamMember(int $userId): User
    {
        return currentTeam()->members()->where('users.id', $userId)->firstOrFail();
    }

    private function memberRole(User $member): string
    {
        return $member->pivot->role ?? 'member';
    }

    public function save(): void
    {
        $this->validate([
            'projectName' => 'required|string|min:2|max:255',
            'projectDescription' => 'nullable|string|max:255',
        ]);

        try {
            $this->authorize('update', $this->project);
            $this->project->update([
                'name' => $this->projectName,
                'description' => $this->projectDescription,
            ]);
            $this->dispatch('success', 'Project updated.');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'You are not allowed to update this project.');
        }
    }

    public function render()
    {
        $resources = $this->section === 'danger'
            ? RailwayResourceMapper::resourcesFor($this->environment)
                ->map(fn ($r) => RailwayResourceMapper::toNode($r, $this->project->uuid, $this->environment->uuid))
                ->all()
            : [];

        $members = $this->section === 'members'
            ? currentTeam()->members()->get()
            : collect();

        $invitations = $this->section === 'members' && auth()->user()->can('manageInvitations', currentTeam())
            ? TeamInvitation::ownedByCurrentTeam()->get()
            : collect();

        return view('livewire.railway.settings', [
            'resources' => $resources,
            'members' => $members,
            'invitations' => $invitations,
            'canManageMembers' => auth()->user()->can('manageMembers', currentTeam()),
            'currentUserId' => auth()->id(),
        ]);
    }
}
