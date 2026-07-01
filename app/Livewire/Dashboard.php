<?php

namespace App\Livewire;

use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

class Dashboard extends Component
{
    public const PROJECT_SORTS = ['name_asc', 'name_desc', 'created_desc', 'updated_desc'];

    public Collection $projects;

    public Collection $servers;

    public Collection $privateKeys;

    #[Url]
    public string $sort = 'name_asc';

    public function mount()
    {
        $this->normalizeSort();
        $this->privateKeys = PrivateKey::ownedByCurrentTeamCached();
        $this->servers = Server::ownedByCurrentTeamCached();
        $this->projects = Project::ownedByCurrentTeam()->with('environments')->get();
    }

    public function updatedSort(): void
    {
        $this->normalizeSort();
    }

    private function normalizeSort(): void
    {
        if (! in_array($this->sort, self::PROJECT_SORTS, true)) {
            $this->sort = 'name_asc';
        }
    }

    #[Computed]
    public function sortedProjects(): Collection
    {
        return match ($this->sort) {
            'name_desc' => $this->projects->sortByDesc(fn ($project) => Str::lower($project->name))->values(),
            'created_desc' => $this->projects->sortByDesc('created_at')->values(),
            'updated_desc' => $this->projects->sortByDesc('updated_at')->values(),
            default => $this->projects->sortBy(fn ($project) => Str::lower($project->name))->values(),
        };
    }

    public function render()
    {
        return view('livewire.dashboard');
    }
}
