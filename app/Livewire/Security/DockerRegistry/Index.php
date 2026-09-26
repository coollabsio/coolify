<?php

namespace App\Livewire\Security\DockerRegistry;

use App\Models\DockerRegistry;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Index extends Component
{
    use AuthorizesRequests;

    public function render()
    {
        $this->authorize('viewAny', DockerRegistry::class);

        $registries = DockerRegistry::ownedByCurrentTeam(['name', 'uuid', 'description', 'registry_url', 'team_id'])->get();

        return view('livewire.security.docker-registry.index', [
            'registries' => $registries,
        ])->layout('components.layout');
    }
}
