<?php

namespace App\Livewire\Server;

use App\Models\EnvironmentVariable;
use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class EnvironmentVariables extends Component
{
    use AuthorizesRequests;

    public Server $server;

    public array $parameters = [];

    public string $newKey = '';

    public ?string $newValue = '';

    protected $listeners = [
        'refreshServerEnvs' => '$refresh',
        'environmentVariableDeleted' => '$refresh',
    ];

    public function mount(string $server_uuid)
    {
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
            $this->parameters = get_route_parameters();
        } catch (\Throwable) {
            return redirect()->route('server.index');
        }
    }

    public function getEnvironmentVariablesProperty()
    {
        return $this->server->environment_variables()->orderBy('key')->get();
    }

    public function submit()
    {
        try {
            $this->authorize('update', $this->server);

            $this->validate([
                'newKey' => 'required|string',
                'newValue' => 'nullable',
            ]);

            $existing = $this->server->environment_variables()->where('key', $this->newKey)->first();
            if ($existing) {
                $this->dispatch('error', "Environment variable '{$this->newKey}' already exists.");

                return;
            }

            $env = new EnvironmentVariable;
            $env->key = $this->newKey;
            $env->value = $this->newValue;
            $env->is_runtime = true;
            $env->is_buildtime = false;
            $env->is_preview = false;
            $env->resourceable_id = $this->server->id;
            $env->resourceable_type = $this->server->getMorphClass();
            $env->save();

            $this->newKey = '';
            $this->newValue = '';
            $this->dispatch('success', 'Environment variable added.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function delete(int $id)
    {
        try {
            $this->authorize('update', $this->server);

            $env = $this->server->environment_variables()->findOrFail($id);
            $env->delete();

            $this->dispatch('success', 'Environment variable deleted.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.server.environment-variables');
    }
}
