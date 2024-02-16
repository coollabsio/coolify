<?php

namespace App\Livewire\Server;

use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Resources extends Component
{
    use AuthorizesRequests;
    public ?Server $server = null;
    public $parameters = [];
    public function getListeners()
    {
        $teamId = auth()->user()->currentTeam()->id;
        return [
            "echo-private:team.{$teamId},ApplicationStatusChanged" => 'refreshStatus',
        ];
    }

    public function refreshStatus() {
        $this->server->refresh();
        $this->dispatch('success', 'Resource statuses refreshed.');
    }
    public function mount() {
        $this->parameters = get_route_parameters();
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid(request()->server_uuid)->first();
            if (is_null($this->server)) {
                return redirect()->route('server.index');
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
    public function render()
    {
        return view('livewire.server.resources');
    }
}
