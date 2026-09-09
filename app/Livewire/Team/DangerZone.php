<?php

namespace App\Livewire\Team;

use App\Actions\Team\DeleteTeam;
use App\Models\Team;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class DangerZone extends Component
{
    use AuthorizesRequests;

    public Team $team;

    public function mount(): void
    {
        $this->team = currentTeam();
    }

    public function delete(): mixed
    {
        try {
            $currentTeam = currentTeam();
            $this->authorize('delete', $currentTeam);
            $newTeam = app(DeleteTeam::class)->handle($currentTeam, auth()->user());
            refreshSession($newTeam);

            return redirect()->route('team.index');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function refreshResources(): void
    {
        $this->team = Team::query()->findOrFail($this->team->id);
        refreshSession($this->team);
    }

    public function render(): mixed
    {
        return view('livewire.team.danger-zone');
    }
}
