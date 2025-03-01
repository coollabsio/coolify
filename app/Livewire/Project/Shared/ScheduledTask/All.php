<?php

namespace App\Livewire\Project\Shared\ScheduledTask;

use App\Models\ScheduledTask;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class All extends Component
{
    #[Locked]
    public $resource;

    #[Locked]
    public array $parameters;

    public Collection $containerNames;

    public ?string $variables = null;

    public function mount()
    {
        $this->parameters = get_route_parameters();
        if ($this->resource->type() === 'service') {
            $this->containerNames = $this->resource->applications()->pluck('name');
            $this->containerNames = $this->containerNames->merge($this->resource->databases()->pluck('name'));
        } elseif ($this->resource->type() === 'application') {
            if ($this->resource->build_pack === 'dockercompose') {
                $parsed = $this->resource->parse();
                $containers = collect(data_get($parsed, 'services'))->keys();
                $this->containerNames = $containers;
            } else {
                $this->containerNames = collect([]);
            }
        }
    }

    #[On('refreshTasks')]
    public function refreshTasks()
    {
        $this->resource->refresh();
    }

}
