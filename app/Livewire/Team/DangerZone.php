<?php

namespace App\Livewire\Team;

use App\Models\Team;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
            $currentTeam->members->each(function ($user) use ($currentTeam): void {
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

    public function render(): mixed
    {
        return view('livewire.team.danger-zone');
    }
}
