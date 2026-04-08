<?php

namespace App\Livewire\User;

use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Index extends Component
{
    use AuthorizesRequests;

    public $users = [];

    public function mount(): void
    {
        $this->authorize('viewAny', User::class);
        $this->loadUsers();
    }

    public function loadUsers(): void
    {
        // Only users that share at least one team with the actor are listed.
        $teamIds = auth()->user()->teams->pluck('id');

        $this->users = User::query()
            ->whereHas('teams', function ($query) use ($teamIds): void {
                $query->whereIn('teams.id', $teamIds);
            })
            ->with(['assignedProjects:id,uuid,name'])
            ->orderBy('name')
            ->get();
    }

    public function render()
    {
        return view('livewire.user.index');
    }
}
