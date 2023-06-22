<?php

namespace App\Http\Livewire\Project\Application\EnvironmentVariable;

use App\Models\EnvironmentVariable as ModelsEnvironmentVariable;
use Livewire\Component;

class Show extends Component
{
    public $parameters;
    public ModelsEnvironmentVariable $env;

    protected $rules = [
        'env.key' => 'required|string',
        'env.value' => 'required|string',
        'env.is_build_time' => 'required|boolean',
    ];
    protected $validationAttributes = [
        'key' => 'key',
        'value' => 'value',
        'is_build_time' => 'build',
    ];
    public function mount()
    {
        $this->parameters = getRouteParameters();
    }
    public function submit()
    {
        $this->validate();
        $this->env->save();
        $this->emit('success', 'Environment variable updated successfully.');
    }
    public function delete()
    {
        $this->env->delete();
        $this->emit('refreshEnvs');
    }
}
