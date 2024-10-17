<?php

namespace App\Livewire\Server;

use App\Actions\Server\RemoveServer;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Component;

class Delete extends Component
{
    use AuthorizesRequests;

    public $server;

    public function delete($password)
    {
        if (! Hash::check($password, Auth::user()->password)) {
            $this->addError('password', 'The provided password is incorrect.');

            return;
        }
        try {
            $this->authorize('delete', $this->server);
            if ($this->server->hasDefinedResources()) {
                $this->dispatch('error', 'Server has defined resources. Please delete them first.');

                return;
            }
            RemoveServer::run($this->server);

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
