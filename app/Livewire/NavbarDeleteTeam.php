<?php

namespace App\Livewire;

use App\Actions\Team\DeleteTeam;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
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
            $newTeam = app(DeleteTeam::class)->handle($currentTeam, auth()->user());
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
