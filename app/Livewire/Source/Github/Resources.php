<?php

namespace App\Livewire\Source\Github;

use App\Models\GithubApp;
use Livewire\Component;

class Resources extends Component
{
    public ?GithubApp $github_app = null;

    public function mount(string $github_app_uuid): void
    {
        $this->github_app = GithubApp::ownedByCurrentTeam()->whereUuid($github_app_uuid)->firstOrFail();

        if (! data_get($this->github_app, 'app_id')) {
            $this->redirectRoute('source.github.show', ['github_app_uuid' => $this->github_app->uuid], navigate: true);
        }
    }

    public function render()
    {
        return view('livewire.source.github.resources');
    }
}
