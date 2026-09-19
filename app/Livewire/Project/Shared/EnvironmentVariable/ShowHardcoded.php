<?php

namespace App\Livewire\Project\Shared\EnvironmentVariable;

use Livewire\Component;

class ShowHardcoded extends Component
{
    public bool $showEnvironmentType = true;

    public array $env;

    public string $key;

    public ?string $value = null;

    public ?string $comment = null;

    public ?string $serviceName = null;

    public bool $isPreview = false;

    public string $composeType = 'literal';

    /** @var list<string> */
    public array $references = [];

    public function mount()
    {
        $this->key = $this->env['key'];
        $this->value = $this->env['value'] ?? null;
        $this->comment = $this->env['comment'] ?? null;
        $this->serviceName = $this->env['service_name'] ?? null;
        $this->composeType = $this->env['compose_type'] ?? 'literal';
        $this->references = $this->env['references'] ?? [];
    }

    public function render()
    {
        return view('livewire.project.shared.environment-variable.show-hardcoded');
    }
}
