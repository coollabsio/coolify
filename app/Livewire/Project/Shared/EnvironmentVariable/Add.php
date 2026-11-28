<?php

namespace App\Livewire\Project\Shared\EnvironmentVariable;

use App\Support\ValidationPatterns;
use App\Traits\EnvironmentVariableAnalyzer;
use App\Traits\HasSecretManagerAutocomplete;
use App\Traits\HasSharedVariableAutocomplete;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Add extends Component
{
    use AuthorizesRequests, EnvironmentVariableAnalyzer, HasSecretManagerAutocomplete, HasSharedVariableAutocomplete;

    protected function secretManagerResource(): ?Model
    {
        if ($this->shared || ! $this->resource) {
            return null;
        }

        return $this->resource;
    }

    protected function sharedVariableResource(): ?Model
    {
        return $this->secretManagerResource();
    }

    public $resource;

    public bool $shared = false;

    public bool $is_preview = false;

    public string $key;

    public ?string $value = null;

    public bool $is_multiline = false;

    public bool $is_literal = false;

    public bool $is_runtime = true;

    public bool $is_buildtime = true;

    public ?string $comment = null;

    public array $problematicVariables = [];

    protected $listeners = ['clearAddEnv' => 'clear'];

    protected function rules(): array
    {
        return [
            'key' => ValidationPatterns::environmentVariableKeyRules(),
            'value' => 'nullable',
            'is_multiline' => 'required|boolean',
            'is_literal' => 'required|boolean',
            'is_runtime' => 'required|boolean',
            'is_buildtime' => 'required|boolean',
            'comment' => 'nullable|string|max:256',
        ];
    }

    protected function messages(): array
    {
        return ValidationPatterns::environmentVariableKeyMessages('key');
    }

    protected $validationAttributes = [
        'key' => 'key',
        'value' => 'value',
        'is_multiline' => 'multiline',
        'is_literal' => 'literal',
        'is_runtime' => 'runtime',
        'is_buildtime' => 'buildtime',
        'comment' => 'comment',
    ];

    public function mount()
    {
        $this->problematicVariables = self::getProblematicVariablesForFrontend();
    }

    public function submit()
    {
        $this->key = ValidationPatterns::normalizeEnvironmentVariableKey($this->key);
        $this->validate();
        $this->dispatch('saveKey', [
            'key' => $this->key,
            'value' => $this->value,
            'is_multiline' => $this->is_multiline,
            'is_literal' => $this->is_literal,
            'is_runtime' => $this->is_runtime,
            'is_buildtime' => $this->is_buildtime,
            'is_preview' => $this->is_preview,
            'comment' => $this->comment,
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
        $this->comment = null;
    }
}
