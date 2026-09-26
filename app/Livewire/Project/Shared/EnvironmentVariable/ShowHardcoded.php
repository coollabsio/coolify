<?php

namespace App\Livewire\Project\Shared\EnvironmentVariable;

use App\Models\EnvironmentVariable;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ShowHardcoded extends Component
{
    public bool $showEnvironmentType = true;

    public bool $selectable = false;

    public array $env;

    public string $key;

    #[Locked]
    public ?string $value = null;

    public ?string $comment = null;

    public ?string $serviceName = null;

    #[Locked]
    public bool $isPreview = false;

    #[Locked]
    public ?string $resourceableType = null;

    #[Locked]
    public ?int $resourceableId = null;

    public function mount()
    {
        $this->key = $this->env['key'];
        $this->value = $this->env['value'] ?? null;
        $this->comment = $this->env['comment'] ?? null;
        $this->serviceName = $this->env['service_name'] ?? null;
    }

    public function copyValue(): ?string
    {
        if (auth()->user()?->isMember() ?? true) {
            return null;
        }

        $environmentVariable = EnvironmentVariable::make([
            'value' => $this->value,
            'is_preview' => $this->isPreview,
            'resourceable_type' => $this->resourceableType,
            'resourceable_id' => $this->resourceableId,
        ]);
        $resource = $environmentVariable->resourceable;

        if (! $resource || auth()->user()->cannot('update', $resource)) {
            return null;
        }

        return $environmentVariable->resolveReferencedValue();
    }

    public function render()
    {
        return view('livewire.project.shared.environment-variable.show-hardcoded');
    }
}
