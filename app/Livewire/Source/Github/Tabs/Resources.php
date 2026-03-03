<?php

namespace App\Livewire\Source\Github\Tabs;

use App\Models\GithubApp;
use Illuminate\Support\Collection;
use Livewire\Component;

class Resources extends Component
{
    public GithubApp $github_app;

    public Collection $applications;

    public function mount(string $githubAppUuid): void
    {
        $this->github_app = GithubApp::ownedByCurrentTeam()->whereUuid($githubAppUuid)->firstOrFail();
        $this->applications = $this->github_app->applications;
    }

    public function render()
    {
        return view('livewire.source.github.tabs.resources');
    }
}
