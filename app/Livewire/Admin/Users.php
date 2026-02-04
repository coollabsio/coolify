<?php

namespace App\Livewire\Admin;

use App\Enums\Role;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Users extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    #[Url]
    public string $search = '';

    public string $status = '';

    public string $newUserName = '';

    public string $newUserEmail = '';

    public bool $newUserIsGlobalAdmin = false;

    public ?int $selectedUserId = null;

    public ?int $assignTeamId = null;

    public string $assignRole = 'member';

    protected $listeners = ['refreshUsers' => '$refresh'];

    public function mount()
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('dashboard');
        }
    }

    public function updatedSearch()
    {
        $this->resetPage();
    }

    public function updatedStatus()
    {
        $this->resetPage();
    }

    public function getUsers()
    {
        $query = User::query()
            ->orderBy('created_at', 'desc');

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('name', 'ilike', "%{$this->search}%")
                    ->orWhere('email', 'ilike', "%{$this->search}%");
            });
        }

        if ($this->status) {
            $query->where('status', $this->status);
        }

        return $query->paginate(20);
    }

    public function getTeams()
    {
        return Team::orderBy('name')->get();
    }

    public function createUser()
    {
        $this->validate([
            'newUserName' => 'required|string|max:255',
            'newUserEmail' => 'required|email|unique:users,email',
        ]);

        try {
            $password = Str::password();

            $user = User::create([
                'name' => $this->newUserName,
                'email' => strtolower($this->newUserEmail),
                'password' => Hash::make($password),
                'is_global_admin' => $this->newUserIsGlobalAdmin,
                'status' => 'active',
                'force_password_reset' => true,
            ]);

            $this->reset(['newUserName', 'newUserEmail', 'newUserIsGlobalAdmin']);
            $this->dispatch('success', "User {$user->email} created successfully. Temporary password: {$password}");
            $this->dispatch('refreshUsers');
        } catch (\Exception $e) {
            return handleError(error: $e, livewire: $this);
        }
    }

    public function toggleGlobalAdmin(int $userId)
    {
        try {
            $user = User::findOrFail($userId);

            // Prevent removing global admin from yourself
            if ($user->id === auth()->id() && $user->is_global_admin) {
                throw new \Exception('You cannot remove your own global admin status.');
            }

            $user->is_global_admin = ! $user->is_global_admin;
            $user->save();

            $status = $user->is_global_admin ? 'granted' : 'revoked';
            $this->dispatch('success', "Global admin {$status} for {$user->email}");
        } catch (\Exception $e) {
            return handleError(error: $e, livewire: $this);
        }
    }

    public function setStatus(int $userId, string $status)
    {
        try {
            $user = User::findOrFail($userId);

            // Prevent suspending yourself
            if ($user->id === auth()->id() && $status === 'suspended') {
                throw new \Exception('You cannot suspend your own account.');
            }

            $user->status = $status;
            $user->save();

            $this->dispatch('success', "User {$user->email} status set to {$status}");
        } catch (\Exception $e) {
            return handleError(error: $e, livewire: $this);
        }
    }

    public function selectUserForTeamAssignment(int $userId)
    {
        $this->selectedUserId = $userId;
        $this->assignTeamId = null;
        $this->assignRole = 'member';
    }

    public function assignToTeam()
    {
        if (! $this->selectedUserId || ! $this->assignTeamId) {
            return;
        }

        try {
            $user = User::findOrFail($this->selectedUserId);
            $team = Team::findOrFail($this->assignTeamId);

            // Check if already a member
            if ($user->teams()->where('team_id', $team->id)->exists()) {
                throw new \Exception("User is already a member of {$team->name}");
            }

            $user->teams()->attach($team->id, ['role' => $this->assignRole]);

            $this->reset(['selectedUserId', 'assignTeamId', 'assignRole']);
            $this->dispatch('success', "User assigned to {$team->name} as {$this->assignRole}");
            $this->dispatch('refreshUsers');
        } catch (\Exception $e) {
            return handleError(error: $e, livewire: $this);
        }
    }

    public function removeFromTeam(int $userId, int $teamId)
    {
        try {
            $user = User::findOrFail($userId);
            $team = Team::findOrFail($teamId);

            // Prevent removing yourself from root team
            if ($user->id === auth()->id() && $teamId === 0) {
                throw new \Exception('You cannot remove yourself from the root team.');
            }

            // Prevent removing if user is only owner
            $teamMembership = $user->teams()->where('team_id', $teamId)->first();
            if ($teamMembership && $teamMembership->pivot->role === 'owner') {
                $otherOwners = $team->members()
                    ->wherePivot('role', 'owner')
                    ->where('user_id', '!=', $userId)
                    ->count();

                if ($otherOwners === 0) {
                    throw new \Exception('Cannot remove the only owner from a team. Transfer ownership first.');
                }
            }

            $user->teams()->detach($teamId);
            $this->dispatch('success', "User removed from {$team->name}");
            $this->dispatch('refreshUsers');
        } catch (\Exception $e) {
            return handleError(error: $e, livewire: $this);
        }
    }

    public function render()
    {
        return view('livewire.admin.users', [
            'users' => $this->getUsers(),
            'teams' => $this->getTeams(),
            'roles' => [
                Role::VIEWER->value => 'Viewer (Read-only)',
                Role::MEMBER->value => 'Member',
                Role::ADMIN->value => 'Admin',
                Role::OWNER->value => 'Owner',
            ],
        ]);
    }
}
