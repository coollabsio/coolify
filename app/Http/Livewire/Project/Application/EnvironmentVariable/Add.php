<?php

namespace App\Http\Livewire\Project\Application\EnvironmentVariable;

use Livewire\Component;

class Add extends Component
{
    public $parameters;
    public bool $is_preview = false;
    public string $key;
    public string $value;
    public bool $is_build_time = false;

    protected $listeners = ['clearAddEnv' => 'clear'];
    protected $rules = [
        'key' => 'required|string',
        'value' => 'required|string',
        'is_build_time' => 'required|boolean',
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
        $this->emitUp('submit', [
            'key' => $this->key,
            'value' => $this->value,
            'is_build_time' => $this->is_build_time,
            'is_preview' => $this->is_preview,
        ]);
    }
    public function clear()
    {
        $this->key = '';
        $this->value = '';
        $this->is_build_time = false;
    }
}
