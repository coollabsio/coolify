<?php

namespace App\Livewire;

use App\Models\InstanceSettings;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Component;

class NavbarDeleteTeam extends Component
{
    public $team;

    public function mount()
    {
        $this->team = currentTeam()->name;
    }

    public function delete($password)
    {
        if (! data_get(InstanceSettings::get(), 'disable_two_step_confirmation')) {
            if (! Hash::check($password, Auth::user()->password)) {
                $this->addError('password', 'The provided password is incorrect.');

                return;
            }
        }

        $currentTeam = currentTeam();
        $currentTeam->delete();

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

        refreshSession();

        return redirect()->route('team.index');
    }

    public function render()
    {
        return view('livewire.navbar-delete-team');
    }
}
