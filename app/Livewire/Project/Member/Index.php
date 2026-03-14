<?php

namespace App\Livewire\Project\Member;

use App\Models\Project;
use App\Models\ProjectInvitation;
use App\Models\ProjectMember;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Index extends Component
{
    use AuthorizesRequests;

    public Project $project;

    public $members = [];

    public $invitations = [];

    public function mount(string $project_uuid): void
    {
        $this->project = Project::where('team_id', currentTeam()->id)
            ->where('uuid', $project_uuid)
            ->firstOrFail();

        $this->loadData();
    }

    public function loadData(): void
    {
        $this->members = ProjectMember::where('project_id', $this->project->id)
            ->with('user')
            ->get();

        if ($this->canManageMembers()) {
            $this->invitations = ProjectInvitation::where('project_id', $this->project->id)->get();
        }
    }

    public function canManageMembers(): bool
    {
        $user = auth()->user();

        // Team admins/owners can always manage
        if ($user->isAdmin() || $user->isOwner()) {
            return true;
        }

        // Project managers can manage
        $membership = $this->project->getProjectMember($user);

        return $membership?->canManage() ?? false;
    }

    public function revokeInvitation(int $invitationId): void
    {
        try {
            if (! $this->canManageMembers()) {
                throw new \Exception('You are not authorized to revoke invitations.');
            }

            $invitation = ProjectInvitation::where('project_id', $this->project->id)
                ->findOrFail($invitationId);

            $invitation->delete();
            $this->loadData();
            $this->dispatch('success', 'Invitation revoked.');
        } catch (\Exception $e) {
            $this->dispatch('error', $e->getMessage());
        }
    }

    protected $listeners = ['refreshProjectMembers' => 'loadData'];

    public function render()
    {
        return view('livewire.project.member.index');
    }
}
