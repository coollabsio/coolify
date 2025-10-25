<?php

namespace App\Livewire\Project\Shared\EnvironmentVariable;

use App\Traits\EnvironmentVariableAnalyzer;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Add extends Component
{
    use AuthorizesRequests, EnvironmentVariableAnalyzer;

    public $parameters;

    public bool $shared = false;

    public bool $is_preview = false;

    public string $key;

    public ?string $value = null;

    public bool $is_multiline = false;

    public bool $is_literal = false;

    public bool $is_runtime = true;

    public bool $is_buildtime = true;

    public array $problematicVariables = [];

    protected $listeners = ['clearAddEnv' => 'clear'];

    protected $rules = [
        'key' => 'required|string',
        'value' => 'nullable',
        'is_multiline' => 'required|boolean',
        'is_literal' => 'required|boolean',
        'is_runtime' => 'required|boolean',
        'is_buildtime' => 'required|boolean',
    ];

    protected $validationAttributes = [
        'key' => 'key',
        'value' => 'value',
        'is_multiline' => 'multiline',
        'is_literal' => 'literal',
        'is_runtime' => 'runtime',
        'is_buildtime' => 'buildtime',
    ];

    public function mount()
    {
        $this->parameters = get_route_parameters();
        $this->problematicVariables = self::getProblematicVariablesForFrontend();
    }

    public function submit()
    {
        $this->validate();
        $this->dispatch('saveKey', [
            'key' => $this->key,
            'value' => $this->value,
            'is_multiline' => $this->is_multiline,
            'is_literal' => $this->is_literal,
            'is_runtime' => $this->is_runtime,
            'is_buildtime' => $this->is_buildtime,
            'is_preview' => $this->is_preview,
        ]);
        $this->clear();
    }

    public function clear()
    {
        $this->key = '';
        $this->value = '';
        $this->is_multiline = false;
        $this->is_literal = false;
        $this->is_runtime = true;
        $this->is_buildtime = true;
    }
}
