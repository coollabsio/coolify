<?php

namespace App\Http\Livewire\Project\Application\EnvironmentVariable;

use App\Models\Application;
use App\Models\EnvironmentVariable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;
use Livewire\Component;

class Add extends Component
{
    public $parameters;
    public string $key;
    public string $value;
    public bool $is_build_time = false;

    protected $rules = [
        'key' => 'required|string',
        'value' => 'required|string',
        'is_build_time' => 'required|boolean',
    ];
    public function mount()
    {
        $this->parameters = Route::current()->parameters();
    }
    public function submit()
    {
        $this->validate();
        try {

            $application_id = Application::where('uuid', $this->parameters['application_uuid'])->firstOrFail()->id;
            EnvironmentVariable::create([
                'key' => $this->key,
                'value' => $this->value,
                'is_build_time' => $this->is_build_time,
                'application_id' => $application_id,
            ]);
            $this->emit('refreshEnvs');
            $this->key = '';
            $this->value = '';
        } catch (mixed $e) {
            dd('asdf');
            if ($e instanceof QueryException) {
                dd($e->errorInfo);
                $this->emit('error', $e->errorInfo[2]);
            } else {
                $this->emit('error', $e);
            }
        }
    }
}
