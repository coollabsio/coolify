<?php

namespace App\Livewire\Project;

use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Access extends Component
{
    use AuthorizesRequests;

    public Project $project;

    public $teamMembers = [];

    public $projectUsers = [];

    #[Validate('required|exists:users,id')]
    public $selectedUserId = '';

    #[Validate('required|in:view_only,deploy,full_access')]
    public $selectedPermissionLevel = 'view_only';

    public function mount(string $project_uuid)
    {
        try {
            $this->project = Project::where('team_id', currentTeam()->id)
                ->where('uuid', $project_uuid)
                ->firstOrFail();

            $this->authorize('update', $this->project);
            $this->loadData();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function loadData()
    {
        // Load team members who don't have project access yet
        $usersWithAccess = ProjectUser::where('project_id', $this->project->id)
            ->pluck('user_id')
            ->toArray();

        $this->teamMembers = currentTeam()->members()
            ->whereNotIn('users.id', $usersWithAccess)
            ->get()
            ->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->pivot->role ?? 'member',
            ])
            ->toArray();

        // Load users with project access
        $this->projectUsers = ProjectUser::where('project_id', $this->project->id)
            ->with('user')
            ->get()
            ->map(fn ($projectUser) => [
                'id' => $projectUser->id,
                'user_id' => $projectUser->user_id,
                'user_name' => $projectUser->user->name,
                'user_email' => $projectUser->user->email,
                'permissions' => $projectUser->permissions,
                'can_view' => $projectUser->canView(),
                'can_deploy' => $projectUser->canDeploy(),
                'can_manage' => $projectUser->canManage(),
                'can_delete' => $projectUser->canDelete(),
                'permission_level' => $projectUser->getPermissionLevel(),
            ])
            ->toArray();
    }

    public function grantAccess()
    {
        try {
            $this->authorize('update', $this->project);
            $this->validate();

            $user = User::findOrFail($this->selectedUserId);

            // Check if user is part of the team
            if (! currentTeam()->members()->where('users.id', $user->id)->exists()) {
                throw new \Exception('User is not a member of this team.');
            }

            ProjectUser::create([
                'project_id' => $this->project->id,
                'user_id' => $user->id,
                'permissions' => ProjectUser::getPermissionsForLevel($this->selectedPermissionLevel),
            ]);

            $this->selectedUserId = '';
            $this->selectedPermissionLevel = 'view_only';
            $this->loadData();

            $this->dispatch('success', 'Access granted to '.$user->name);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function updatePermissions(int $projectUserId, string $level)
    {
        try {
            $this->authorize('update', $this->project);

            $projectUser = ProjectUser::where('id', $projectUserId)
                ->where('project_id', $this->project->id)
                ->firstOrFail();

            $projectUser->setPermissions(ProjectUser::getPermissionsForLevel($level))->save();
            $this->loadData();

            $this->dispatch('success', 'Permissions updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function revokeAccess(int $projectUserId)
    {
        try {
            $this->authorize('update', $this->project);

            $projectUser = ProjectUser::where('id', $projectUserId)
                ->where('project_id', $this->project->id)
                ->firstOrFail();

            $userName = $projectUser->user->name;
            $projectUser->delete();

            $this->loadData();

            $this->dispatch('success', 'Access revoked from '.$userName);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function grantFullAccessToAll()
    {
        try {
            $this->authorize('update', $this->project);

            $teamMembers = currentTeam()->members()->get();

            foreach ($teamMembers as $member) {
                // Skip owners and admins - they already have access via role
                if (in_array($member->pivot->role, ['owner', 'admin'])) {
                    continue;
                }

                $existingAccess = ProjectUser::where('project_id', $this->project->id)
                    ->where('user_id', $member->id)
                    ->first();

                if ($existingAccess) {
                    $existingAccess->grantFullAccess()->save();
                } else {
                    ProjectUser::create([
                        'project_id' => $this->project->id,
                        'user_id' => $member->id,
                        'permissions' => ProjectUser::FULL_ACCESS_PERMISSIONS,
                    ]);
                }
            }

            $this->loadData();
            $this->dispatch('success', 'Full access granted to all team members.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function revokeAllAccess()
    {
        try {
            $this->authorize('update', $this->project);

            ProjectUser::where('project_id', $this->project->id)->delete();

            $this->loadData();
            $this->dispatch('success', 'All project access revoked.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.access');
    }
}
