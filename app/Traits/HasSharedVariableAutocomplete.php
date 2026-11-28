<?php

namespace App\Traits;

use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;

/**
 * Gives the env-var-input autocomplete the shared variable keys that the user can reference.
 * The scopes come from the resource itself, not from the route: components can mount in a
 * Livewire update request (for example rows loaded with wire:init), where the page route
 * parameters are not available.
 */
trait HasSharedVariableAutocomplete
{
    /**
     * The resource that the variable belongs to, or null when there is none (shared variable forms).
     */
    abstract protected function sharedVariableResource(): ?Model;

    /**
     * @return array{team: ?Team, project: ?Project, environment: ?Environment, server: ?Server}
     */
    #[Computed]
    public function sharedVariableScopes(): array
    {
        $resource = $this->sharedVariableResource();
        $environment = data_get($resource, 'environment');

        return [
            'team' => $resource ? $resource->team() : currentTeam(),
            'project' => data_get($environment, 'project'),
            'environment' => $environment,
            'server' => data_get($resource, 'destination.server'),
        ];
    }

    /**
     * @return array{team: list<string>, project: list<string>, environment: list<string>, server: list<string>}
     */
    #[Computed]
    public function availableSharedVariables(): array
    {
        return collect($this->sharedVariableScopes())
            ->map(fn (?Model $scope) => $scope && Auth::user()?->can('viewSharedVariables', $scope)
                ? $scope->environment_variables()->pluck('key')->all()
                : [])
            ->all();
    }
}
