<?php

namespace App\Livewire\Team;

use App\Enums\Role;
use App\Models\User;
use Livewire\Component;
use Livewire\WithPagination;

class AdminView extends Component
{
    use WithPagination;

    public string $search = '';

    public string $teamFilter = 'all';

    public string $sort = 'name_asc';

    public int $perPage = 10;

    public function mount()
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('dashboard');
        }
    }

    public function updatedSearch(): void
    {
        if (! isInstanceAdmin()) {
            return;
        }

        $this->resetPage();
    }

    public function updatedTeamFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSort(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->perPage = max(1, min(100, $this->perPage));

        $this->resetPage();
    }

    public function submitSearch(): void
    {
        if (! isInstanceAdmin()) {
            return;
        }

        $this->resetPage();
    }

    public function delete($id, $password, $selectedActions = [])
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('dashboard');
        }

        if (! verifyPasswordConfirmation($password, $this)) {
            return 'The provided password is incorrect.';
        }

        if (! auth()->user()->isInstanceAdmin()) {
            return $this->dispatch('error', 'You are not authorized to delete users');
        }

        $user = User::find($id);
        if (! $user) {
            return $this->dispatch('error', 'User not found');
        }

        if ($error = $this->deletionError(auth()->user(), $user)) {
            return $this->dispatch('error', $error);
        }

        try {
            $user->delete();
            auditLog('ui.user.deleted', [
                'team_id' => currentTeam()?->id,
                'resource' => 'user',
                'user_name' => $user->name,
                'deleted_user_id' => $user->id,
                'deleted_user_email' => $user->email,
            ]);
            $this->resetPage();

            return true;
        } catch (\Exception $e) {
            return $this->dispatch('error', $e->getMessage());
        }
    }

    /**
     * Instance admins may delete other users, but never the root user, their
     * own account (use the profile instead), or a user whose role in the root
     * team is higher than their own.
     */
    private function deletionError(User $actor, User $target): ?string
    {
        if ($target->id === 0) {
            return 'The root user cannot be deleted.';
        }

        if ($target->id === $actor->id) {
            return 'Delete your own account from your profile.';
        }

        $actorRole = $this->rootTeamRole($actor);
        $targetRole = $this->rootTeamRole($target);

        if (! $actorRole || ($targetRole && $targetRole->gt($actorRole))) {
            return 'You cannot delete a user with a higher role in the root team.';
        }

        return null;
    }

    private function rootTeamRole(User $user): ?Role
    {
        $role = $user->teams()->where('teams.id', 0)->first()?->pivot?->role;

        return $role ? Role::tryFrom($role) : null;
    }

    public function render()
    {
        abort_unless(isInstanceAdmin(), 403);

        $search = trim($this->search);
        $teamId = currentTeam()->id;
        $users = User::query()
            ->where('id', '!=', auth()->id())
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($this->teamFilter === 'current', function ($query) use ($teamId): void {
                $query->whereHas('teams', fn ($teamQuery) => $teamQuery->where('teams.id', $teamId));
            })
            ->when($this->teamFilter === 'outside', function ($query) use ($teamId): void {
                $query->whereDoesntHave('teams', fn ($teamQuery) => $teamQuery->where('teams.id', $teamId));
            })
            ->when($this->sort === 'name_desc', fn ($query) => $query->orderByDesc('name'))
            ->when($this->sort === 'email_asc', fn ($query) => $query->orderBy('email'))
            ->when($this->sort === 'email_desc', fn ($query) => $query->orderByDesc('email'))
            ->when($this->sort === 'name_asc', fn ($query) => $query->orderBy('name'))
            ->orderBy('id')
            ->paginate($this->perPage);

        return view('livewire.team.admin-view', [
            'users' => $users,
        ]);
    }
}
