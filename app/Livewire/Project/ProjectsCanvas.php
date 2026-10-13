<?php

namespace App\Livewire\Project;

use App\Livewire\Concerns\RemembersCanvasView;
use App\Models\Project;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Read-only canvas of every project of the team. The page is a shell:
 * resources/js/environment-canvas.js renders it from Api\ProjectCanvasController
 * at /canvas/api with the browser session.
 */
class ProjectsCanvas extends Component
{
    use AuthorizesRequests;
    use RemembersCanvasView;

    /** The team that owned the page at mount; the canvas API calls use it, not the session team. */
    #[Locked]
    public int $teamId;

    public function mount(): void
    {
        $this->authorize('viewAny', Project::class);
        $this->teamId = currentTeam()->id;
        $this->rememberView('projects', 'canvas');
    }

    public function render(): View
    {
        return view('livewire.project.projects-canvas')->layout('layouts.app', ['fullBleed' => true]);
    }
}
