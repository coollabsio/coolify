<?php

namespace App\Livewire\Project\Shared\EnvironmentVariable;

use App\Models\EnvironmentVariable as ModelsEnvironmentVariable;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class Show extends Component
{
    public $parameters;
    public ModelsEnvironmentVariable $env;
    public ?string $modalId = null;
    public bool $isDisabled = false;
    public bool $isLocked = false;
    public string $type;

    protected $rules = [
        'env.key' => 'required|string',
        'env.value' => 'nullable',
        'env.is_build_time' => 'required|boolean',
        'env.is_shown_once' => 'required|boolean',
    ];
    protected $validationAttributes = [
        'key' => 'Key',
        'value' => 'Value',
        'is_build_time' => 'Build Time',
        'is_shown_once' => 'Shown Once',
    ];

    public function mount()
    {
        $this->modalId = new Cuid2(7);
        $this->parameters = get_route_parameters();
        $this->checkEnvs();
    }
    public function checkEnvs()
    {
        $this->isDisabled = false;
        if (str($this->env->key)->startsWith('SERVICE_FQDN') || str($this->env->key)->startsWith('SERVICE_URL')) {
            $this->isDisabled = true;
        }
        if ($this->env->is_shown_once) {
            $this->isLocked = true;
        }
    }
    public function lock()
    {
        $this->env->is_shown_once = true;
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
        $this->validate();
        $this->env->save();
        $this->dispatch('success', 'Environment variable updated successfully.');
        $this->dispatch('refreshEnvs');
    }

    public function delete()
    {
        try {
            $this->env->delete();
            $this->dispatch('refreshEnvs');
        } catch (\Exception $e) {
            return handleError($e);
        }
    }
}
