<?php

namespace App\Livewire\Server\EnvironmentVariable;

use App\Models\ServerEnvironmentVariable;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Show extends Component
{
    use AuthorizesRequests;

    public ServerEnvironmentVariable $env;

    public ?string $value = null;

    public bool $is_multiline = false;

    public bool $is_literal = false;

    protected $rules = [
        'env.key' => 'required|string',
        'env.value' => 'nullable',
        'env.is_multiline' => 'required|boolean',
        'env.is_literal' => 'required|boolean',
        'env.is_shown_once' => 'required|boolean',
    ];

    public function mount(ServerEnvironmentVariable $env)
    {
        $this->env = $env;
        $this->is_multiline = $env->is_multiline;
        $this->is_literal = $env->is_literal;
    }

    public function submit()
    {
        try {
            $this->authorize('update', $this->env->server);
            $this->validate();
            $this->env->save();
            $this->dispatch('success', 'Environment variable updated.');
            $this->dispatch('refreshEnvs');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function delete()
    {
        try {
            $this->authorize('update', $this->env->server);
            $this->env->delete();
            $this->dispatch('success', 'Environment variable deleted.');
            $this->dispatch('environmentVariableDeleted');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.server.environment-variable.show');
    }
}
