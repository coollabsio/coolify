<?php

namespace App\Http\Livewire\Project\Application\EnvironmentVariable;

use App\Models\Application;
use App\Models\EnvironmentVariable as ModelsEnvironmentVariable;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Route;
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
    public function mount()
    {
        $this->parameters = Route::current()->parameters();
    }
    public function submit()
    {
        $this->validate();
        $this->env->save();
    }
    public function delete()
    {
        $this->env->delete();
        $this->emit('reloadWindow');
    }
}
