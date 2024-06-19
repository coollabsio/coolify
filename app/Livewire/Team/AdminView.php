<?php

namespace App\Livewire\Team;

use App\Models\Team;
use App\Models\User;
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
                ray('Deleting resource: '.$resource->name);
                $resource->forceDelete();
            }
            ray('Deleting server: '.$server->name);
            $server->forceDelete();
        }

        $projects = $team->projects;
        foreach ($projects as $project) {
            ray('Deleting project: '.$project->name);
            $project->forceDelete();
        }
        $team->members()->detach($user->id);
        ray('Deleting team: '.$team->name);
        $team->delete();
    }

    public function delete($id)
    {
        if (! auth()->user()->isInstanceAdmin()) {
            return $this->dispatch('error', 'You are not authorized to delete users');
        }
        $user = User::find($id);
        $teams = $user->teams;
        foreach ($teams as $team) {
            ray($team->name);
            $user_alone_in_team = $team->members->count() === 1;
            if ($team->id === 0) {
                if ($user_alone_in_team) {
                    ray('user is alone in the root team, do nothing');

                    return $this->dispatch('error', 'User is alone in the root team, cannot delete');
                }
            }
            if ($user_alone_in_team) {
                ray('user is alone in the team');
                $this->finalizeDeletion($user, $team);

                continue;
            }
            ray('user is not alone in the team');
            if ($user->isOwner()) {
                $found_other_owner_or_admin = $team->members->filter(function ($member) {
                    return $member->pivot->role === 'owner' || $member->pivot->role === 'admin';
                })->where('id', '!=', $user->id)->first();

                if ($found_other_owner_or_admin) {
                    ray('found other owner or admin');
                    $team->members()->detach($user->id);

                    continue;
                } else {
                    $found_other_member_who_is_not_owner = $team->members->filter(function ($member) {
                        return $member->pivot->role === 'member';
                    })->first();
                    if ($found_other_member_who_is_not_owner) {
                        ray('found other member who is not owner');
                        $found_other_member_who_is_not_owner->pivot->role = 'owner';
                        $found_other_member_who_is_not_owner->pivot->save();
                        $team->members()->detach($user->id);
                    } else {
                        // This should never happen as if the user is the only member in the team, the team should be deleted already.
                        ray('found no other member who is not owner');
                        $this->finalizeDeletion($user, $team);
                    }

                    continue;
                }
            } else {
                ray('user is not owner');
                $team->members()->detach($user->id);
            }
        }
        ray('Deleting user: '.$user->name);
        $user->delete();
        $this->getUsers();
    }

    public function render()
    {
        return view('livewire.team.admin-view');
    }
}
