<?php

namespace App\Livewire\Project\Database;

use App\Models\ServiceDatabase;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneRedis;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Import extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public ?int $resourceId = null;

    #[Locked]
    public ?string $resourceType = null;

    public string $resourceStatus = '';

    public string $resourceUuid = '';

    public bool $unsupported = false;

    public function getListeners(): array
    {
        $listeners = ['databaseUpdated' => 'refreshStatus'];

        $user = Auth::user();
        if (! $user) {
            return $listeners;
        }

        $listeners["echo-private:user.{$user->id},DatabaseStatusChanged"] = 'refreshStatus';

        $team = $user->currentTeam();
        if ($team) {
            $listeners["echo-private:team.{$team->id},ServiceChecked"] = 'refreshStatus';
        }

        return $listeners;
    }

    public function mount(): void
    {
        $resource = $this->resolveResourceFromRoute();
        $this->authorize('view', $resource);

        $this->resourceId = $resource->id;
        $this->resourceType = get_class($resource);

        $this->refreshStatus();
    }

    public function refreshStatus(): void
    {
        $resource = $this->resolveStoredResource();
        $this->authorize('view', $resource);

        $resource->refresh();
        $this->resourceUuid = $resource->uuid;
        $this->resourceStatus = $resource->status ?? '';
        $this->unsupported = $this->isUnsupportedResource($resource);
    }

    public function render(): View
    {
        return view('livewire.project.database.import');
    }

    private function resolveResourceFromRoute(): object
    {
        $parameters = get_route_parameters();
        $teamId = data_get(Auth::user()?->currentTeam(), 'id');
        $databaseUuid = data_get($parameters, 'database_uuid');
        $stackServiceUuid = data_get($parameters, 'stack_service_uuid');

        if ($databaseUuid) {
            $resource = getResourceByUuid($databaseUuid, $teamId);
            if ($resource) {
                return $resource;
            }

            abort(404);
        }

        if ($stackServiceUuid) {
            $project = currentTeam()
                ->projects()
                ->select('id', 'uuid', 'team_id')
                ->where('uuid', data_get($parameters, 'project_uuid'))
                ->firstOrFail();
            $environment = $project->environments()
                ->select('id', 'uuid', 'name', 'project_id')
                ->where('uuid', data_get($parameters, 'environment_uuid'))
                ->firstOrFail();
            $service = $environment->services()->whereUuid(data_get($parameters, 'service_uuid'))->firstOrFail();
            $resource = $service->databases()->whereUuid($stackServiceUuid)->first();
            if ($resource) {
                return $resource;
            }
        }

        abort(404);
    }

    private function resolveStoredResource(): object
    {
        if ($this->resourceId === null || $this->resourceType === null) {
            return $this->resolveResourceFromRoute();
        }

        $resource = $this->resourceType::find($this->resourceId);
        if ($resource) {
            return $resource;
        }

        abort(404);
    }

    private function isUnsupportedResource(object $resource): bool
    {
        if (
            $resource instanceof StandaloneRedis ||
            $resource instanceof StandaloneKeydb ||
            $resource instanceof StandaloneDragonfly ||
            $resource instanceof StandaloneClickhouse
        ) {
            return true;
        }

        if ($resource instanceof ServiceDatabase) {
            $dbType = $resource->databaseType();

            return str_contains($dbType, 'redis') ||
                str_contains($dbType, 'keydb') ||
                str_contains($dbType, 'dragonfly') ||
                str_contains($dbType, 'clickhouse');
        }

        return false;
    }
}
