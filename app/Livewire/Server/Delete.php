<?php

namespace App\Livewire\Server;

use App\Actions\Server\DeleteServer;
use App\Models\InstanceSettings;
use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Component;

class Delete extends Component
{
    use AuthorizesRequests;

    public Server $server;

    public function mount(string $server_uuid)
    {
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function delete($password)
    {
        if (! data_get(InstanceSettings::get(), 'disable_two_step_confirmation')) {
            if (! Hash::check($password, Auth::user()->password)) {
                $this->addError('password', 'The provided password is incorrect.');

                return;
            }
        }
        try {
            $this->authorize('delete', $this->server);
            if ($this->server->hasDefinedResources()) {
                $this->dispatch('error', 'Server has defined resources. Please delete them first.');

                return;
            }
            $this->server->delete();
            DeleteServer::dispatch($this->server);

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
