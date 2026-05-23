<?php

namespace App\Livewire;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class NavbarDeleteTeam extends Component
{
    public $team;

    public function mount()
    {
        $this->team = currentTeam()->name;
    }

    public function delete($password, $selectedActions = [])
    {
        if (! verifyPasswordConfirmation($password, $this)) {
            return 'The provided password is incorrect.';
        }

        $currentTeam = currentTeam();

        // Get the user's first remaining team BEFORE deleting (so currentTeam() doesn't return null)
        $user = Auth::user();
        $newTeam = $user->teams()->where('teams.id', '!=', $currentTeam->id)->first();
        if ($newTeam) {
            $user->forceFill(['currentTeam_id' => $newTeam->id])->save();
        }

        $currentTeam->members->each(function ($user) use ($currentTeam) {
            if ($user->id === Auth::id()) {
                return;
            }
            $user->teams()->detach($currentTeam);
            $session = DB::table('sessions')->where('user_id', $user->id)->first();
            if ($session) {
                DB::table('sessions')->where('id', $session->id)->delete();
            }
        });

        $currentTeam->delete();

        refreshSession($newTeam);

        return redirectRoute($this, 'team.index');
    }

    public function render()
    {
        return view('livewire.navbar-delete-team');
    }
}
