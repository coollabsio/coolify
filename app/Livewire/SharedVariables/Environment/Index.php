<?php

namespace App\Livewire\SharedVariables\Environment;

use App\Models\Environment;
use App\Models\Project;
use Illuminate\Support\Collection;
use Livewire\Component;

class Index extends Component
{
    public Collection $projects;

    public function mount()
    {
        $this->projects = Project::ownedByCurrentTeamCached()
            ->each(fn (Project $project) => $project->setRelation('environments', $project->environments
                ->filter(fn (Environment $environment) => auth()->user()->can('viewSharedVariables', $environment))
                ->values()))
            ->filter(fn (Project $project) => $project->environments->isNotEmpty())
            ->values();
    }

    public function render()
    {
        return view('livewire.shared-variables.environment.index');
    }
}
