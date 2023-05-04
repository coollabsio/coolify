<?php

namespace App\Http\Livewire\Project\Application;

use App\Models\Application;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Route;
use Livewire\Component;

class EnvironmentVariable extends Component
{
    public $parameters;
    public $env;
    public string|null $keyName = null;
    public string|null $value = null;
    public bool $isBuildOnly = false;
    public bool $isNewEnv = false;

    public function mount()
    {
        $this->parameters = Route::current()->parameters();
        if (data_get($this->env, 'value') !== null) {
            $this->value = $this->env['value'];
            $this->isBuildOnly = $this->env['isBuildOnly'];
        } else {
            $this->isNewEnv = true;
        }
    }
    public function updateEnv()
    {
        $application = Application::where('uuid', $this->parameters['application_uuid'])->first();
        $application->environment_variables->set("{$this->keyName}.value", $this->value);
        $application->environment_variables->set("{$this->keyName}.isBuildOnly", $this->isBuildOnly);
        $application->save();
    }
    public function submit()
    {
        $this->updateEnv();
        $this->emit('reloadWindow');
    }
    public function delete()
    {
        $application = Application::where('uuid', $this->parameters['application_uuid'])->first();
        $application->environment_variables->forget($this->keyName);
        $application->save();
        $this->emit('reloadWindow');
    }
}
