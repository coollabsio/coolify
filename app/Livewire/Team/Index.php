<?php

namespace App\Livewire\Team;

use App\Models\Team;
use App\Models\TeamInvitation;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class Index extends Component
{
    public $invitations = [];

    public Team $team;

    protected $rules = [
        'team.name' => 'required|min:3|max:255',
        'team.description' => 'nullable|min:3|max:255',
    ];

    protected $validationAttributes = [
        'team.name' => 'name',
        'team.description' => 'description',
    ];

    public function mount()
    {
        $this->team = currentTeam();

        if (auth()->user()->isAdminFromSession()) {
            $this->invitations = TeamInvitation::whereTeamId(currentTeam()->id)->get();
        }
    }

    public function render()
    {
        return view('livewire.team.index');
    }

    public function submit()
    {
        $this->validate();
        try {
            $this->team->save();
            refreshSession();
            $this->dispatch('success', 'Team updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

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
}
