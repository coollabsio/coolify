<?php

namespace App\Livewire\Project;

use App\Livewire\Concerns\RemembersCanvasView;
use App\Models\Environment;
use App\Models\Project;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Full-screen canvas of one environment's deployed resources. The page is a shell:
 * resources/js/environment-canvas.js renders the canvas and calls
 * Api\EnvironmentCanvasController at /canvas/api with the browser session.
 */
class EnvironmentCanvas extends Component
{
    use AuthorizesRequests;
    use RemembersCanvasView;

    #[Locked]
    public Project $project;

    #[Locked]
    public Environment $environment;

    public function mount(string $project_uuid, string $environment_uuid): void
    {
        $this->project = Project::ownedByCurrentTeam()->whereUuid($project_uuid)->firstOrFail();
        $this->environment = $this->project->environments()->whereUuid($environment_uuid)->firstOrFail();
        $this->authorize('view', $this->environment);
        $this->rememberView('environment', 'canvas');
    }

    public function render(): View
    {
        return view('livewire.project.environment-canvas')->layout('layouts.app', ['fullBleed' => true]);
    }
}
