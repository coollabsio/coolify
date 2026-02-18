<?php

namespace App\Livewire\Server\EnvironmentVariable;

use Livewire\Component;

class Add extends Component
{
    public string $key = '';

    public ?string $value = null;

    public bool $is_multiline = false;

    public bool $is_literal = false;

    public bool $is_shown_once = false;

    protected $listeners = ['clearAddEnv' => 'clear'];

    protected $rules = [
        'key' => 'required|string',
        'value' => 'nullable',
        'is_multiline' => 'required|boolean',
        'is_literal' => 'required|boolean',
        'is_shown_once' => 'required|boolean',
    ];

    public function submit()
    {
        $this->validate();
        $this->dispatch('saveKey', [
            'key' => $this->key,
            'value' => $this->value,
            'is_multiline' => $this->is_multiline,
            'is_literal' => $this->is_literal,
            'is_shown_once' => $this->is_shown_once,
        ]);
        $this->clear();
    }

    public function clear()
    {
        $this->key = '';
        $this->value = '';
        $this->is_multiline = false;
        $this->is_literal = false;
        $this->is_shown_once = false;
    }

    public function render()
    {
        return view('livewire.server.environment-variable.add');
    }
}
