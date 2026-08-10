<?php

namespace App\Livewire;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class NavbarDeleteTeam extends Component
{
    use AuthorizesRequests;

    public $team;

    public function mount()
    {
        $this->team = currentTeam()->name;
    }

    public function delete($password, $selectedActions = [])
    {
        try {
            if (! verifyPasswordConfirmation($password, $this)) {
                return 'The provided password is incorrect.';
            }

            $currentTeam = currentTeam();
            $this->authorize('delete', $currentTeam);

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

            Cache::forget('user:'.Auth::id().':team:'.$currentTeam->id);
            $currentTeam->delete();

            $newTeam = Auth::user()->teams()->first();
            refreshSession($newTeam);

            return redirect()->route('team.index');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.navbar-delete-team');
    }
}
