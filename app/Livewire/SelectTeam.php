<?php

namespace App\Livewire;

use App\Models\Team;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class SelectTeam extends Component
{
    // mount()/selectTeam() intentionally have no return type: Livewire's
    // redirect() returns a Redirector (not an Illuminate RedirectResponse),
    // matching the convention in sibling components such as SwitchTeam.
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

    public function render(): View
    {
        return view('livewire.select-team', [
            'teams' => auth()->user()->teams,
        ])->layout('layouts.simple');
    }
}
