<?php

namespace App\Livewire\Server;

use App\Models\EnvironmentVariable;
use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class EnvironmentVariableItem extends Component
{
    use AuthorizesRequests;

    public EnvironmentVariable $env;

    public Server $server;

    public string $key;

    public ?string $value = null;

    public bool $isLocked = false;

    protected $rules = [
        'key' => 'required|string',
        'value' => 'nullable',
    ];

    public function mount()
    {
        $this->key = $this->env->key;
        $this->value = $this->env->value;
        $this->isLocked = $this->env->is_shown_once;
    }

    public function submit()
    {
        try {
            $this->authorize('update', $this->server);
            $this->validate();

            $this->env->key = $this->key;
            if (! $this->isLocked) {
                $this->env->value = $this->value;
            }
            $this->env->save();

            $this->dispatch('success', 'Environment variable updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function lock()
    {
        try {
            $this->authorize('update', $this->server);

            $this->env->is_shown_once = true;
            $this->env->save();
            $this->isLocked = true;

            $this->dispatch('success', 'Environment variable locked.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function delete()
    {
        try {
            $this->authorize('update', $this->server);

            $this->env->delete();
            $this->dispatch('success', 'Environment variable deleted.');
            $this->dispatch('refreshServerEnvs');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.server.environment-variable-item');
    }
}
