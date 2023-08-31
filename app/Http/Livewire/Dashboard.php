<?php

namespace App\Http\Livewire;

use App\Models\Project;
use App\Models\S3Storage;
use App\Models\Server;
use Livewire\Component;
use Log;

class Dashboard extends Component
{
    public int $projects = 0;
    public int $servers = 0;
    public int $s3s = 0;
    public int $resources = 0;

    public function mount()
    {
        $this->servers = Server::ownedByCurrentTeam()->get()->count();
        $this->s3s = S3Storage::ownedByCurrentTeam()->get()->count();
        $projects = Project::ownedByCurrentTeam()->get();
        foreach ($projects as $project) {
            $this->resources += $project->applications->count();
            $this->resources += $project->postgresqls->count();
        }
        $this->projects = $projects->count();
    }
    public function render()
    {
        return view('livewire.dashboard');
    }
}
