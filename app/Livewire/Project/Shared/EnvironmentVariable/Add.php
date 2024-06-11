<?php

namespace App\Livewire\Project\Shared\EnvironmentVariable;

use Livewire\Component;

class Add extends Component
{
    public $parameters;

    public bool $shared = false;

    public bool $is_preview = false;

    public string $key;

    public ?string $value = null;

    public bool $is_build_time = false;

    public bool $is_multiline = false;

    public bool $is_literal = false;

    protected $listeners = ['clearAddEnv' => 'clear'];

    protected $rules = [
        'key' => 'required|string',
        'value' => 'nullable',
        'is_build_time' => 'required|boolean',
        'is_multiline' => 'required|boolean',
        'is_literal' => 'required|boolean',
    ];

    protected $validationAttributes = [
        'key' => 'key',
        'value' => 'value',
        'is_build_time' => 'build',
        'is_multiline' => 'multiline',
        'is_literal' => 'literal',
    ];

    public function mount()
    {
        $this->parameters = get_route_parameters();
    }

    public function submit()
    {
        $this->validate();
        if (str($this->value)->startsWith('{{') && str($this->value)->endsWith('}}')) {
            $type = str($this->value)->after('{{')->before('.')->value;
            if (! collect(SHARED_VARIABLE_TYPES)->contains($type)) {
                $this->dispatch('error', 'Invalid  shared variable type.', 'Valid types are: team, project, environment.');

                return;
            }
        }
        $this->dispatch('saveKey', [
            'key' => $this->key,
            'value' => $this->value,
            'is_build_time' => $this->is_build_time,
            'is_multiline' => $this->is_multiline,
            'is_literal' => $this->is_literal,
            'is_preview' => $this->is_preview,
        ]);
        $this->clear();
    }

    public function clear()
    {
        $this->key = '';
        $this->value = '';
        $this->is_build_time = false;
        $this->is_multiline = false;
        $this->is_literal = false;
    }
}
