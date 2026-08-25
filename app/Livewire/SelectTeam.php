<?php

namespace App\Livewire;

use App\Models\Team;
use Livewire\Component;

class SelectTeam extends Component
{
    public function mount()
    {
        $user = auth()->user();

        // A team is already active, or the user has at most one team: nothing to pick.
        if ($user->currentTeam() || $user->teams->count() <= 1) {
            $resolved = $user->resolveStoredTeam();
            if ($resolved) {
                refreshSession($resolved);
            }

            return redirect()->route('dashboard');
        }
    }

    public function selectTeam(int $teamId)
    {
        $user = auth()->user();
        if (! $user->teams->contains('id', $teamId)) {
            return;
        }
        $team = Team::find($teamId);
        if (! $team) {
            return;
        }
        refreshSession($team);

        return redirect()->route('dashboard');
    }

    public function render()
    {
        return view('livewire.select-team', [
            'teams' => auth()->user()->teams,
        ])->layout('layouts.simple');
    }
}
