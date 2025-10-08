<?php

namespace App\Livewire\Project\Shared\EnvironmentVariable;

use App\Models\EnvironmentVariable as ModelsEnvironmentVariable;
use App\Models\SharedEnvironmentVariable;
use App\Traits\EnvironmentVariableAnalyzer;
use App\Traits\EnvironmentVariableProtection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Show extends Component
{
    use AuthorizesRequests, EnvironmentVariableAnalyzer, EnvironmentVariableProtection;

    public $parameters;

    public ModelsEnvironmentVariable|SharedEnvironmentVariable $env;

    public bool $isDisabled = false;

    public bool $isLocked = false;

    public bool $isSharedVariable = false;

    public string $type;

    public string $key;

    public ?string $value = null;

    public ?string $real_value = null;

    public bool $is_shared = false;

    public bool $is_multiline = false;

    public bool $is_literal = false;

    public bool $is_shown_once = false;

    public bool $is_runtime = true;

    public bool $is_buildtime = true;

    public bool $is_required = false;

    public bool $is_really_required = false;

    public bool $is_redis_credential = false;

    public array $problematicVariables = [];

    protected $listeners = [
        'refreshEnvs' => 'refresh',
        'refresh',
        'compose_loaded' => '$refresh',
    ];

    protected $rules = [
        'key' => 'required|string',
        'value' => 'nullable',
        'is_multiline' => 'required|boolean',
        'is_literal' => 'required|boolean',
        'is_shown_once' => 'required|boolean',
        'is_runtime' => 'required|boolean',
        'is_buildtime' => 'required|boolean',
        'real_value' => 'nullable',
        'is_required' => 'required|boolean',
    ];

    public function mount()
    {
        $this->syncData();
        if ($this->env->getMorphClass() === \App\Models\SharedEnvironmentVariable::class) {
            $this->isSharedVariable = true;
        }
        $this->parameters = get_route_parameters();
        $this->checkEnvs();
        if ($this->type === 'standalone-redis' && ($this->env->key === 'REDIS_PASSWORD' || $this->env->key === 'REDIS_USERNAME')) {
            $this->is_redis_credential = true;
        }
        $this->problematicVariables = self::getProblematicVariablesForFrontend();
    }

    public function getResourceProperty()
    {
        return $this->env->resourceable ?? $this->env;
    }

    public function refresh()
    {
        $this->syncData();
        $this->checkEnvs();
    }

    public function syncData(bool $toModel = false)
    {
        if ($toModel) {
            if ($this->isSharedVariable) {
                $this->validate([
                    'key' => 'required|string',
                    'value' => 'nullable',
                    'is_multiline' => 'required|boolean',
                    'is_literal' => 'required|boolean',
                    'is_shown_once' => 'required|boolean',
                    'real_value' => 'nullable',
                ]);
            } else {
                $this->validate();
                $this->env->is_required = $this->is_required;
                $this->env->is_runtime = $this->is_runtime;
                $this->env->is_buildtime = $this->is_buildtime;
                $this->env->is_shared = $this->is_shared;
            }
            $this->env->key = $this->key;
            $this->env->value = $this->value;
            $this->env->is_multiline = $this->is_multiline;
            $this->env->is_literal = $this->is_literal;
            $this->env->is_shown_once = $this->is_shown_once;
            $this->env->save();
        } else {
            $this->key = $this->env->key;
            $this->value = $this->env->value;
            $this->is_multiline = $this->env->is_multiline;
            $this->is_literal = $this->env->is_literal;
            $this->is_shown_once = $this->env->is_shown_once;
            $this->is_runtime = $this->env->is_runtime ?? true;
            $this->is_buildtime = $this->env->is_buildtime ?? true;
            $this->is_required = $this->env->is_required ?? false;
            $this->is_really_required = $this->env->is_really_required ?? false;
            $this->is_shared = $this->env->is_shared ?? false;
            $this->real_value = $this->env->real_value;
        }
    }

    public function checkEnvs()
    {
        $this->isDisabled = false;
        if (str($this->env->key)->startsWith('SERVICE_FQDN') || str($this->env->key)->startsWith('SERVICE_URL') || str($this->env->key)->startsWith('SERVICE_NAME')) {
            $this->isDisabled = true;
        }
        if ($this->env->is_shown_once) {
            $this->isLocked = true;
        }
    }

    public function serialize()
    {
        data_forget($this->env, 'real_value');
    }

    public function lock()
    {
        $this->authorize('update', $this->env);

        $this->env->is_shown_once = true;
        if ($this->isSharedVariable) {
            unset($this->env->is_required);
        }
        $this->serialize();
        $this->env->save();
        $this->checkEnvs();
        $this->dispatch('refreshEnvs');
    }

    public function instantSave()
    {
        $this->submit();
    }

    public function submit()
    {
        try {
            $this->authorize('update', $this->env);

            if (! $this->isSharedVariable && $this->is_required && str($this->value)->isEmpty()) {
                $oldValue = $this->env->getOriginal('value');
                $this->value = $oldValue;
                $this->dispatch('error', 'Required environment variables cannot be empty.');

                return;
            }

            $this->serialize();
            $this->syncData(true);
            $this->dispatch('success', 'Environment variable updated.');
            $this->dispatch('envsUpdated');
            $this->dispatch('configurationChanged');
        } catch (\Exception $e) {
            return handleError($e);
        }
    }

    public function delete()
    {
        try {
            $this->authorize('delete', $this->env);

            // Check if the variable is used in Docker Compose
            if ($this->type === 'service' || $this->type === 'application' && $this->env->resourceable?->docker_compose) {
                [$isUsed, $reason] = $this->isEnvironmentVariableUsedInDockerCompose($this->env->key, $this->env->resourceable?->docker_compose);

                if ($isUsed) {
                    $this->dispatch('error', "Cannot delete environment variable '{$this->env->key}' <br><br>Please remove it from the Docker Compose file first.");

                    return;
                }
            }

            $this->env->delete();
            $this->dispatch('environmentVariableDeleted');
            $this->dispatch('success', 'Environment variable deleted successfully.');
        } catch (\Exception $e) {
            return handleError($e);
        }
    }
}
