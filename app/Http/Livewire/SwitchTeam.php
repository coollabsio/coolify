<?php

namespace App\Http\Livewire;

use App\Models\Team;
use Livewire\Component;

class SwitchTeam extends Component
{
    public function switch_to($team_id)
    {
        if (!auth()->user()->teams->contains($team_id)) {
            return;
        }
        $team_to_switch_to = Team::find($team_id);
        if (!$team_to_switch_to) {
            return;
        }
        session(['currentTeam' => $team_to_switch_to]);
        return redirect(request()->header('Referer'));
    }
}
