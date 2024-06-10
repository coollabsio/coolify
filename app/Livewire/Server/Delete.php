<?php

namespace App\Livewire\Server;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Delete extends Component
{
    use AuthorizesRequests;

    public $server;

    public function delete()
    {
        try {
            $this->authorize('delete', $this->server);
            if ($this->server->hasDefinedResources()) {
                $this->dispatch('error', 'Server has defined resources. Please delete them first.');

                return;
            }
            $this->server->delete();

            return redirect()->route('server.index');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.server.delete');
    }
}
