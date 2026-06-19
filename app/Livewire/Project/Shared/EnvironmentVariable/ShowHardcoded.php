<?php

namespace App\Livewire\Project\Shared\EnvironmentVariable;

use Livewire\Component;

class ShowHardcoded extends Component
{
    public array $env;

    public string $key;

    public ?string $value = null;

    public ?string $comment = null;

    public ?string $serviceName = null;

    public function mount()
    {
        $this->key = $this->env['key'];
        $this->value = $this->env['value'] ?? null;
        $this->comment = $this->env['comment'] ?? null;
        $this->serviceName = $this->env['service_name'] ?? null;
    }

    public function render()
    {
        return view('livewire.project.shared.environment-variable.show-hardcoded');
    }
}
