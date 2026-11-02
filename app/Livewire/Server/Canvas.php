<?php

namespace App\Livewire\Server;

use App\Livewire\Concerns\RemembersCanvasView;
use App\Models\Server;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Read-only canvas of all servers (or one server) with their resources.
 * The page is a shell: resources/js/environment-canvas.js renders it from
 * Api\ServerCanvasController at /canvas/api with the browser session.
 */
class Canvas extends Component
{
    use AuthorizesRequests;
    use RemembersCanvasView;

    #[Locked]
    public ?Server $server = null;

    /** The team that owned the page at mount; the canvas API calls use it, not the session team. */
    #[Locked]
    public int $teamId;

    public function mount(?string $server_uuid = null): void
    {
        if ($server_uuid !== null) {
            $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
            $this->authorize('view', $this->server);
            $this->teamId = $this->server->team_id;
            $this->rememberView('server_resources', 'canvas');
        } else {
            $this->teamId = currentTeam()->id;
            $this->rememberView('servers', 'canvas');
        }
    }

    public function render(): View
    {
        return view('livewire.server.canvas')->layout('layouts.app', ['fullBleed' => true]);
    }
}
