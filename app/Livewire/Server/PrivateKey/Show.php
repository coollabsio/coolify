<?php

namespace App\Livewire\Server\PrivateKey;

use App\Models\PrivateKey;
use App\Models\Server;
use Livewire\Component;

class Show extends Component
{
    public ?Server $server = null;

    public $privateKeys = [];

    public $parameters = [];

    public function mount()
    {
        $this->parameters = get_route_parameters();
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid(request()->server_uuid)->first();
            if (is_null($this->server)) {
                return redirect()->route('server.index');
            }
            $this->privateKeys = PrivateKey::ownedByCurrentTeam()->get()->where('is_git_related', false);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.server.private-key.show');
    }
}
