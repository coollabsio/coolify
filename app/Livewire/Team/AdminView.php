<?php

namespace App\Livewire\Team;

use App\Models\User;
use Livewire\Component;
use Livewire\WithPagination;

class AdminView extends Component
{
    use WithPagination;

    public string $search = '';

    public string $teamFilter = 'all';

    public string $sort = 'name_asc';

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

        try {
            $user->delete();
            $this->resetPage();

            return true;
        } catch (\Exception $e) {
            return $this->dispatch('error', $e->getMessage());
        }
    }

    public function render()
    {
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
            ->paginate(10);

        return view('livewire.team.admin-view', [
            'users' => $users,
        ]);
    }
}
