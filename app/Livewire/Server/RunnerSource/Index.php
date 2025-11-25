<?php

namespace App\Livewire\Server\RunnerSource;

use App\Models\GitHubRunnerSource;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;

class Index extends Component
{
    public ?Collection $sources = null;

    public function mount()
    {
        $this->sources = GitHubRunnerSource::where('team_id', currentTeam()->id)
            ->withCount(['servers', 'runners' => fn ($q) => $q->where('status', 'running')])
            ->get();
    }

    public function render()
    {
        return view('livewire.server.runner-source.index');
    }
}
