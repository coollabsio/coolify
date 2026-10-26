<?php

namespace App\Livewire\Project;

use App\Livewire\Concerns\CreatesEnvironments;
use App\Livewire\Concerns\RemembersCanvasView;
use App\Models\Project;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Read-only canvas of every environment of a project. The page is a shell:
 * resources/js/environment-canvas.js renders it from Api\ProjectCanvasController
 * at /canvas/api with the browser session.
 */
class ProjectCanvas extends Component
{
    use AuthorizesRequests;
    use CreatesEnvironments;
    use RemembersCanvasView;

    #[Locked]
    public Project $project;

    public function mount(string $project_uuid): void
    {
        $this->project = Project::ownedByCurrentTeam()->whereUuid($project_uuid)->firstOrFail();
        $this->authorize('view', $this->project);
        $this->rememberView('project', 'canvas');
    }

    public function render(): View
    {
        return view('livewire.project.project-canvas')->layout('layouts.app', ['fullBleed' => true]);
    }
}
