<?php

namespace App\Livewire\Team;

use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Component;

class AdminView extends Component
{
    public $users;

    public ?string $search = '';

    public bool $lots_of_users = false;

    private $number_of_users_to_show = 20;

    public function mount()
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('dashboard');
        }
        $this->getUsers();
    }

    public function submitSearch()
    {
        if ($this->search !== '') {
            $this->users = User::where(function ($query) {
                $query->where('name', 'like', "%{$this->search}%")
                    ->orWhere('email', 'like', "%{$this->search}%");
            })->get()->filter(function ($user) {
                return $user->id !== auth()->id();
            });
        } else {
            $this->getUsers();
        }
    }

    public function getUsers()
    {
        $users = User::where('id', '!=', auth()->id())->get();
        if ($users->count() > $this->number_of_users_to_show) {
            $this->lots_of_users = true;
            $this->users = $users->take($this->number_of_users_to_show);
        } else {
            $this->lots_of_users = false;
            $this->users = $users;
        }
    }

    private function finalizeDeletion(User $user, Team $team)
    {
        $servers = $team->servers;
        foreach ($servers as $server) {
            $resources = $server->definedResources();
            foreach ($resources as $resource) {
                $resource->forceDelete();
            }
            $server->forceDelete();
        }

        $projects = $team->projects;
        foreach ($projects as $project) {
            $project->forceDelete();
        }
        $team->members()->detach($user->id);
        $team->delete();
    }

    public function delete($id, $password)
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('dashboard');
        }
        if (! data_get(InstanceSettings::get(), 'disable_two_step_confirmation')) {
            if (! Hash::check($password, Auth::user()->password)) {
                $this->addError('password', 'The provided password is incorrect.');

                return;
            }
        }
        if (! auth()->user()->isInstanceAdmin()) {
            return $this->dispatch('error', 'You are not authorized to delete users');
        }
        $user = User::find($id);
        $teams = $user->teams;
        foreach ($teams as $team) {
            $user_alone_in_team = $team->members->count() === 1;
            if ($team->id === 0) {
                if ($user_alone_in_team) {
                    return $this->dispatch('error', 'User is alone in the root team, cannot delete');
                }
            }
            if ($user_alone_in_team) {
                $this->finalizeDeletion($user, $team);

                continue;
            }
            if ($user->isOwner()) {
                $found_other_owner_or_admin = $team->members->filter(function ($member) {
                    return $member->pivot->role === 'owner' || $member->pivot->role === 'admin';
                })->where('id', '!=', $user->id)->first();

                if ($found_other_owner_or_admin) {
                    $team->members()->detach($user->id);

                    continue;
                } else {
                    $found_other_member_who_is_not_owner = $team->members->filter(function ($member) {
                        return $member->pivot->role === 'member';
                    })->first();
                    if ($found_other_member_who_is_not_owner) {
                        $found_other_member_who_is_not_owner->pivot->role = 'owner';
                        $found_other_member_who_is_not_owner->pivot->save();
                        $team->members()->detach($user->id);
                    } else {
                        $this->finalizeDeletion($user, $team);
                    }

                    continue;
                }
            } else {
                $team->members()->detach($user->id);
            }
        }
        $user->delete();
        $this->getUsers();
    }

    public function render()
    {
        return view('livewire.team.admin-view');
    }
}
