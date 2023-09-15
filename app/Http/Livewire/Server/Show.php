<?php

namespace App\Http\Livewire\Server;

use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Show extends Component
{
    use AuthorizesRequests;
    public ?Server $server = null;
    public function mount()
    {
        try {
            $this->server = Server::ownedByCurrentTeam(['name', 'description', 'ip', 'port', 'user', 'proxy'])->whereUuid(request()->server_uuid)->first();
            if (is_null($this->server)) {
                return redirect()->route('server.all');
            }
        } catch (\Throwable $e) {
            return general_error_handler(err: $e, that: $this);
        }
    }
    public function render()
    {
        return view('livewire.server.show');
    }
}
