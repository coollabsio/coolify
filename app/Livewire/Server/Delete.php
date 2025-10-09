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

    public bool $delete_from_hetzner = false;

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
            DeleteServer::dispatch(
                $this->server->id,
                $this->delete_from_hetzner,
                $this->server->hetzner_server_id,
                $this->server->cloud_provider_token_id,
                $this->server->team_id
            );

            return redirect()->route('server.index');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        $checkboxes = [];

        if ($this->server->hetzner_server_id) {
            $checkboxes[] = [
                'id' => 'delete_from_hetzner',
                'label' => 'Also delete server from Hetzner Cloud',
                'default_warning' => 'The actual server on Hetzner Cloud will NOT be deleted.',
            ];
        }

        return view('livewire.server.delete', [
            'checkboxes' => $checkboxes,
        ]);
    }
}
