<?php

namespace App\Livewire\Railway;

use App\Livewire\Railway\Concerns\LoadsProjectContext;
use App\Support\RailwayResourceMapper;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.railway')]
class Observability extends Component
{
    use LoadsProjectContext;

    public function mount(string $project_uuid, string $environment_uuid): void
    {
        $this->loadProjectContext($project_uuid, $environment_uuid);
    }

    public function render()
    {
        $resources = RailwayResourceMapper::resourcesFor($this->environment);

        $services = $resources->map(fn ($r) => RailwayResourceMapper::toNode($r, $this->project->uuid, $this->environment->uuid))->all();
        $online = collect($services)->where('online', true)->count();

        return view('livewire.railway.observability', [
            'services' => $services,
            'online' => $online,
            'total' => count($services),
        ]);
    }
}
