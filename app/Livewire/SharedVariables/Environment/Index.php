<?php

namespace App\Livewire\SharedVariables\Environment;

use App\Models\Project;
use Illuminate\Support\Collection;
use Livewire\Component;

class Index extends Component
{
    public Collection $projects;

    public function mount()
    {
        $this->projects = Project::ownedByCurrentTeam()->get();
    }

    public function render()
    {
        return view('livewire.shared-variables.environment.index');
    }
}
