<?php

namespace App\Livewire\Server;

use App\Livewire\Concerns\RemembersCanvasView;
use App\Models\Server;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Renderless;
use Livewire\Component;

class Index extends Component
{
    use RemembersCanvasView;

    public ?Collection $servers = null;

    public function mount(): void
    {
        if ($this->prefersCanvasView('servers')) {
            $this->redirectRoute('server.canvas.index', navigate: true);

            return;
        }

        $this->servers = Server::ownedByCurrentTeamCached();
    }

    /**
     * Load one server address on demand, so the list never puts addresses in the page source.
     */
    #[Renderless]
    public function loadServerAddress(string $uuid): ?string
    {
        $server = Server::whereTeamId(currentTeam()->id)->where('uuid', $uuid)->first(['id', 'team_id', 'ip']);
        if ($server === null) {
            return null;
        }

        $this->authorize('view', $server);

        return $server->ip;
    }

    public function render()
    {
        return view('livewire.server.index');
    }
}
