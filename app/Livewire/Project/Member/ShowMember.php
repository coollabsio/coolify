<?php

namespace App\Livewire\Project\Member;

use App\Enums\ProjectMemberRole;
use App\Models\Project;
use App\Models\ProjectMember;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class ShowMember extends Component
{
    use AuthorizesRequests;

    public ProjectMember $projectMember;

    public Project $project;

    public function changeRole(string $role): void
    {
        try {
            $this->checkAuthorization();

            if (! ProjectMemberRole::tryFrom($role)) {
                throw new \Exception('Invalid role.');
            }

            // Cannot change own role
            if ($this->projectMember->user_id === auth()->id()) {
                throw new \Exception('You cannot change your own role.');
            }

            $this->projectMember->update(['role' => $role]);
            $this->dispatch('success', 'Role updated.');
            $this->dispatch('refreshProjectMembers');
        } catch (\Exception $e) {
            $this->dispatch('error', $e->getMessage());
        }
    }

    public function remove(): void
    {
        try {
            $this->checkAuthorization();

            // Cannot remove yourself
            if ($this->projectMember->user_id === auth()->id()) {
                throw new \Exception('You cannot remove yourself.');
            }

            $this->projectMember->delete();
            $this->dispatch('success', 'Member removed.');
            $this->dispatch('refreshProjectMembers');
        } catch (\Exception $e) {
            $this->dispatch('error', $e->getMessage());
        }
    }

    private function checkAuthorization(): void
    {
        $user = auth()->user();
        if ($user->isAdmin() || $user->isOwner()) {
            return;
        }

        $membership = $this->project->getProjectMember($user);
        if (! $membership?->canManage()) {
            throw new \Exception('You are not authorized to perform this action.');
        }
    }

    public function render()
    {
        return view('livewire.project.member.show-member');
    }
}
