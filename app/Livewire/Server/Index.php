<?php

namespace App\Livewire\Server;

use App\Livewire\Concerns\RemembersCanvasView;
use App\Models\Server;
use Illuminate\Database\Eloquent\Collection;
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

    public function render()
    {
        return view('livewire.server.index');
    }
}
