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

    protected $listeners = ['clearAddEnv' => 'clear'];
    protected $rules = [
        'key' => 'required|string',
        'value' => 'required|string',
        'is_build_time' => 'required|boolean',
    ];
    public function mount()
    {
        $this->parameters = getParameters();
    }
    public function submit()
    {
        $this->validate();
        $this->emitUp('submit', [
            'key' => $this->key,
            'value' => $this->value,
            'is_build_time' => $this->is_build_time,
        ]);
    }
    public function clear()
    {
        $this->key = '';
        $this->value = '';
        $this->is_build_time = false;
    }
}
