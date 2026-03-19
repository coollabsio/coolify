<?php

namespace App\Livewire\Server;

use App\Models\EnvironmentVariable as EnvironmentVariableModel;
use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class EnvironmentVariable extends Component
{
    use AuthorizesRequests;

    public Server $server;

    public array $parameters = [];

    public string $key = '';

    public ?string $value = null;

    public ?string $comment = null;

    public bool $is_multiline = false;

    public bool $is_literal = false;

    protected $listeners = [
        'refreshServerEnvs' => '$refresh',
        'environmentVariableDeleted' => '$refresh',
    ];

    protected $rules = [
        'key' => 'required|string',
        'value' => 'nullable',
        'comment' => 'nullable|string|max:256',
        'is_multiline' => 'required|boolean',
        'is_literal' => 'required|boolean',
    ];

    public function mount(string $server_uuid): void
    {
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
            $this->parameters = get_route_parameters();
        } catch (\Throwable) {
            redirect()->route('server.index');
        }
    }

    public function getEnvironmentVariablesProperty()
    {
        return $this->server->environment_variables()->orderBy('key')->get();
    }

    public function submit(): void
    {
        try {
            $this->authorize('update', $this->server);
            $this->validate();

            $exists = $this->server->environment_variables()->where('key', $this->key)->first();
            if ($exists) {
                $this->dispatch('error', "Environment variable '{$this->key}' already exists.");

                return;
            }

            $env = new EnvironmentVariableModel;
            $env->key = $this->key;
            $env->value = $this->value;
            $env->comment = $this->comment;
            $env->is_multiline = $this->is_multiline;
            $env->is_literal = $this->is_literal;
            $env->is_runtime = true;
            $env->is_buildtime = false;
            $env->is_preview = false;
            $env->resourceable_id = $this->server->id;
            $env->resourceable_type = Server::class;
            $env->save();

            $this->key = '';
            $this->value = '';
            $this->comment = null;
            $this->is_multiline = false;
            $this->is_literal = false;

            unset($this->environmentVariables);
            $this->dispatch('success', 'Environment variable added.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function delete(int $id): void
    {
        try {
            $this->authorize('update', $this->server);
            $env = $this->server->environment_variables()->findOrFail($id);
            $env->delete();
            unset($this->environmentVariables);
            $this->dispatch('success', 'Environment variable deleted.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
