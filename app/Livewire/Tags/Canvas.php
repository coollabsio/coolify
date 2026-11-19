<?php

namespace App\Livewire\Tags;

use App\Livewire\Concerns\CreatesTags;
use App\Livewire\Concerns\RemembersCanvasView;
use App\Models\Tag;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Full-screen canvas of the team's tags and the resources that have them.
 * The page is a shell: resources/js/environment-canvas.js renders it from
 * Api\TagCanvasController at /canvas/api with the browser session.
 */
class Canvas extends Component
{
    use AuthorizesRequests;
    use CreatesTags;
    use RemembersCanvasView;

    /** The team that owned the page at mount; the canvas API calls use it, not the session team. */
    #[Locked]
    public int $teamId;

    public function mount(): void
    {
        $this->authorize('viewAny', Tag::class);
        $this->teamId = currentTeam()->id;
        $this->rememberView('tags', 'canvas');
    }

    public function render(): View
    {
        return view('livewire.tags.canvas')->layout('layouts.app', ['fullBleed' => true]);
    }
}
