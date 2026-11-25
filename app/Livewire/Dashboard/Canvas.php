<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Concerns\RemembersCanvasView;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Dashboard as a canvas: every server of the team with its resources and their running deployments.
 * The page is a shell: resources/js/environment-canvas.js renders it from
 * Api\ServerCanvasController::dashboard at /canvas/api with the browser session.
 */
class Canvas extends Component
{
    use RemembersCanvasView;

    /** The team that owned the page at mount; the canvas API calls use it, not the session team. */
    #[Locked]
    public int $teamId;

    public function mount(): void
    {
        $this->teamId = currentTeam()->id;
        $this->rememberView('dashboard', 'canvas');
    }

    public function render(): View
    {
        return view('livewire.dashboard.canvas')->layout('layouts.app', ['fullBleed' => true]);
    }
}
