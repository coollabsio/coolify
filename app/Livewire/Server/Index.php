<?php

namespace App\Livewire\Server;

use App\Models\Node;
use App\Models\Server;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;

class Index extends Component
{
    public ?Collection $servers = null;

    public ?Collection $nodes = null;

    public function mount()
    {
        $this->servers = Server::ownedByCurrentTeamCached();
        $this->nodes = isDev() && config('constants.sentinel.host_enabled', false)
            ? Node::query()->where('team_id', currentTeam()->id)->orderBy('name')->get()
            : new Collection;
    }

    public function render()
    {
        return view('livewire.server.index');
    }
}
