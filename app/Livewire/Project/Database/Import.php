<?php

namespace App\Livewire\Project\Database;

use App\Models\ServiceDatabase;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneRedis;
use App\Traits\ListensToTeamChannel;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Import extends Component
{
    use AuthorizesRequests;
    use ListensToTeamChannel;

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

        return [
            ...$listeners,
            ...$this->teamChannelListeners(['ServiceChecked' => 'refreshStatus']),
        ];
    }

    public function mount(Model $resource): void
    {
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
        return view('livewire.project.database.import', [
            'resource' => $this->resolveStoredResource(),
        ]);
    }

    private function resolveStoredResource(): Model
    {
        return $this->resourceType::find($this->resourceId) ?? abort(404);
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
