<?php

namespace App\Livewire;

use App\Models\Team;
use Livewire\Component;

class SwitchTeam extends Component
{
    public string $selectedTeamId = 'default';

    public function mount()
    {
        $this->selectedTeamId = auth()->user()->currentTeam()->id;
    }

    public function updatedSelectedTeamId()
    {
        $this->switch_to($this->selectedTeamId);
    }

    public function switch_to($team_id)
    {
        if (! auth()->user()->teams->contains($team_id)) {
            return;
        }
        $team_to_switch_to = Team::find($team_id);
        if (! $team_to_switch_to) {
            return;
        }
        refreshSession($team_to_switch_to);

        return redirect('dashboard');
    }
}
