<?php

namespace App\Livewire\Server;

use App\Livewire\Concerns\RemembersCanvasView;
use App\Models\Server;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;

/**
 * The Servers page. It lists Docker servers and, when the cluster stack is enabled,
 * groups cluster servers (Node models) by cluster above them.
 */
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

    public function render(): View
    {
        return view('livewire.server.index', [
            'clusterServersEnabled' => isDev() && config('constants.sentinel.host_enabled', false),
        ]);
    }
}
