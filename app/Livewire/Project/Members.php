<?php

namespace App\Livewire\Project;

use App\Models\Project;
use App\Models\ProjectInvitation;
use App\Models\ProjectMember;
use App\Models\User;
use Livewire\Component;

class Members extends Component
{
    public Project $project;

    public string $email = '';

    public string $role = 'member';

    public function mount(string $project_uuid): void
    {
        try {
            $this->project = Project::where('team_id', currentTeam()->id)
                ->where('uuid', $project_uuid)
                ->firstOrFail();
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function invite(): void
    {
        $this->validate([
            'email' => ['required', 'email'],
            'role' => ['required', 'in:member,admin'],
        ]);

        try {
            $user = User::whereEmail(strtolower($this->email))->first();

            if ($user && $this->project->isProjectMember($user->id)) {
                $this->dispatch('error', 'User is already a project member.');

                return;
            }

            if ($this->project->invitations()->where('email', strtolower($this->email))->exists()) {
                $this->dispatch('error', 'An invitation for this email already exists.');

                return;
            }

            if ($user) {
                ProjectMember::create([
                    'project_id' => $this->project->id,
                    'user_id' => $user->id,
                    'role' => $this->role,
                    'invited_by' => auth()->id(),
                ]);
                $this->dispatch('success', 'Member added.');
            } else {
                ProjectInvitation::create([
                    'project_id' => $this->project->id,
                    'email' => $this->email,
                    'role' => $this->role,
                    'invited_by' => auth()->id(),
                ]);
                $this->dispatch('success', 'Invitation sent.');
            }

            $this->email = '';
            $this->role = 'member';
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function updateRole(int $memberId, string $role): void
    {
        try {
            $member = ProjectMember::where('project_id', $this->project->id)->findOrFail($memberId);
            $member->update(['role' => $role]);
            $this->dispatch('success', 'Role updated.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function removeMember(int $memberId): void
    {
        try {
            ProjectMember::where('project_id', $this->project->id)->findOrFail($memberId)->delete();
            $this->dispatch('success', 'Member removed.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function cancelInvitation(int $invitationId): void
    {
        try {
            ProjectInvitation::where('project_id', $this->project->id)->findOrFail($invitationId)->delete();
            $this->dispatch('success', 'Invitation cancelled.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.members', [
            'members' => $this->project->members()->with('user')->get(),
            'pendingInvitations' => $this->project->invitations()->get(),
        ]);
    }
}
