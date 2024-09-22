<?php

namespace App\Livewire\Server;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;

class Delete extends Component
{
    use AuthorizesRequests;

    public $server;

    public function delete($password)
    {
        if (!Hash::check($password, Auth::user()->password)) {
            $this->addError('password', 'The provided password is incorrect.');
            return;
        }
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
