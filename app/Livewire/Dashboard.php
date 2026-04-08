<?php

namespace App\Livewire;

use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use Illuminate\Support\Collection;
use Livewire\Component;

class Dashboard extends Component
{
    public Collection $projects;

    public Collection $servers;

    public Collection $privateKeys;

    public function mount()
    {
        // Scoped client users see only their assigned projects, so the
        // team-wide dashboard is meaningless for them — send them straight
        // to the projects list instead. Initialize the typed collections
        // first so render() will not blow up if Livewire still calls it.
        if (auth()->check() && auth()->user()->isClient()) {
            $this->privateKeys = collect();
            $this->servers = collect();
            $this->projects = collect();
            $this->redirect(route('project.index'), navigate: true);

            return;
        }

        $this->privateKeys = PrivateKey::ownedByCurrentTeamCached();
        $this->servers = Server::ownedByCurrentTeamCached();
        $this->projects = Project::ownedByCurrentTeam()->with('environments')->get();
    }

    public function render()
    {
        return view('livewire.dashboard');
    }
}
