<?php

namespace App\Livewire;

use Illuminate\Support\Facades\DB;
use Livewire\Component;

class NavbarDeleteTeam extends Component
{
    public function delete()
    {
        $currentTeam = currentTeam();
        $currentTeam->delete();

        $currentTeam->members->each(function ($user) use ($currentTeam) {
            if ($user->id === auth()->user()->id) {
                return;
            }
            $user->teams()->detach($currentTeam);
            $session = DB::table('sessions')->where('user_id', $user->id)->first();
            if ($session) {
                DB::table('sessions')->where('id', $session->id)->delete();
            }
        });

        refreshSession();

        return redirect()->route('team.index');
    }

    public function render()
    {
        return view('livewire.navbar-delete-team');
    }
}
