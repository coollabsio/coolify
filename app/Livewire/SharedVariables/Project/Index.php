<?php

namespace App\Livewire\SharedVariables\Project;

use App\Models\Project;
use Illuminate\Support\Collection;
use Livewire\Component;

class Index extends Component
{
    public Collection $projects;

    public function mount()
    {
        $this->projects = Project::ownedByCurrentTeamCached()
            ->filter(fn (Project $project) => auth()->user()->can('viewSharedVariables', $project))
            ->values();
    }

    public function render()
    {
        return view('livewire.shared-variables.project.index');
    }
}
