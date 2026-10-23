<?php

namespace App\Livewire\Project\Shared;

use App\Models\Application;
use App\Models\Service;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\StandaloneSqlite;
use App\Support\RuntimeLogTargets;
use App\Traits\ListensToTeamChannel;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;

class Logs extends Component
{
    use ListensToTeamChannel;

    public ?string $type = null;

    public Application|Service|StandalonePostgresql|StandaloneRedis|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse|StandaloneSqlite $resource;

    public Collection $servers;

    public Collection $containers;

    public array $serverContainers = [];

    /** @var array<int, string> */
    public array $serverErrors = [];

    public $container = [];

    public $parameters;

    public $query;

    public $status;

    public $serviceSubType;

    public $cpu;

    public bool $containersLoaded = false;

    #[Url(as: 'pull_request_id', except: '0')]
    public string $pullRequestId = '0';

    #[Url(as: 'service', except: '')]
    public string $service = '';

    private bool $syncingSelection = false;

    public function getListeners()
    {
        return $this->teamChannelListeners([
            'ServiceChecked' => 'loadAllContainers',
        ]);
    }

    public function loadAllContainers()
    {
        try {
            foreach ($this->servers as $server) {
                unset($this->serverErrors[$server->id]);
                $this->serverContainers[$server->id] = $this->getContainersForServer($server);
            }
            $this->containersLoaded = true;
            $this->syncLogSelection();
        } catch (\Exception $e) {
            $this->containersLoaded = true;
            $this->syncLogSelection();

            return handleError($e, $this);
        }
    }

    public function updatedPullRequestId(): void
    {
        $this->syncLogSelection();
    }

    public function updatedService(): void
    {
        $this->syncLogSelection();
    }

    public function syncLogSelection(): void
    {
        if ($this->syncingSelection || ! $this->containersLoaded) {
            return;
        }

        $this->syncingSelection = true;

        $targets = $this->selectionTargets();
        $this->pullRequestId = $targets['deployment'];
        $this->service = $targets['service'];

        $this->syncingSelection = false;
    }

    private function getContainersForServer($server)
    {
        if (! $server->isFunctional()) {
            return [];
        }

        try {
            if ($server->isSwarm()) {
                $containers = collect([
                    [
                        'ID' => $this->resource->uuid,
                        'Names' => $this->resource->uuid.'_'.$this->resource->uuid,
                    ],
                ]);

                return $containers->toArray();
            }

            // Docker labels differ by resource type:
            // applications → coolify.applicationUuid, services → coolify.serviceUuid, databases → coolify.databaseUuid
            $containers = match (true) {
                $this->resource instanceof Application => getCurrentApplicationContainerStatus(
                    $server,
                    $this->resource,
                    includePullrequests: true
                ),
                $this->resource instanceof Service => getCurrentServiceContainerStatus(
                    $server,
                    $this->resource
                ),
                default => getCurrentDatabaseContainerStatus(
                    $server,
                    $this->resource
                ),
            };

            if ($containers && $containers->count() > 0) {
                return $containers->sort()->toArray();
            }

            return [];
        } catch (\Exception $e) {
            // Keep the error for this server so the page does not report it as "no containers".
            $this->serverErrors[$server->id] = $e->getMessage();

            return [];
        }
    }

    public function mount()
    {
        try {
            $this->containers = collect();
            $this->servers = collect();
            $this->serverContainers = [];
            $this->parameters = get_route_parameters();
            $this->query = request()->query();
            if (data_get($this->parameters, 'application_uuid')) {
                $this->type = 'application';
                $this->resource = Application::ownedByCurrentTeam()->where('uuid', $this->parameters['application_uuid'])->firstOrFail();
                $this->status = $this->resource->status;
                if ($this->resource->destination->server->isFunctional()) {
                    $server = $this->resource->destination->server;
                    $this->servers = $this->servers->push($server);
                }
                foreach ($this->resource->additional_servers as $server) {
                    if ($server->isFunctional()) {
                        $this->servers = $this->servers->push($server);
                    }
                }
            } elseif (data_get($this->parameters, 'database_uuid')) {
                $this->type = 'database';
                $resource = getResourceByUuid($this->parameters['database_uuid'], data_get(auth()->user()->currentTeam(), 'id'));
                if (is_null($resource)) {
                    abort(404);
                }
                $this->resource = $resource;
                $this->status = $this->resource->status;
                if ($this->resource->destination->server->isFunctional()) {
                    $server = $this->resource->destination->server;
                    $this->servers = $this->servers->push($server);
                }
                $this->container = $this->resource->uuid;
                $this->containers->push($this->container);
            } elseif (data_get($this->parameters, 'service_uuid')) {
                $this->type = 'service';
                $this->resource = Service::ownedByCurrentTeam()->where('uuid', $this->parameters['service_uuid'])->firstOrFail();
                $this->resource->applications()->get()->each(function ($application) {
                    $this->containers->push(data_get($application, 'name').'-'.data_get($this->resource, 'uuid'));
                });
                $this->resource->databases()->get()->each(function ($database) {
                    $this->containers->push(data_get($database, 'name').'-'.data_get($this->resource, 'uuid'));
                });
                if ($this->resource->server->isFunctional()) {
                    $server = $this->resource->server;
                    $this->servers = $this->servers->push($server);
                }
            }
            $this->containers = $this->containers->sort();
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    /**
     * @return array{
     *     deployments: list<array{value: string, label: string, labelClass: string}>,
     *     services: list<array{value: string, label: string, dot: string, description?: string}>,
     *     deployment: string,
     *     service: string,
     *     sources: list<array{server: string, container: string, label: string}>,
     *     streamKey: string,
     * }
     */
    private function selectionTargets(): array
    {
        return RuntimeLogTargets::select(
            $this->logServerPayload(),
            $this->pullRequestId,
            $this->service,
            $this->type === 'application',
        );
    }

    /**
     * @return list<array{uuid: string, name: string, containers: list<array<string, mixed>>}>
     */
    private function logServerPayload(): array
    {
        if (! isset($this->servers)) {
            return [];
        }

        return $this->servers
            ->filter(fn ($server): bool => count($this->serverContainers[$server->id] ?? []) > 0)
            ->map(fn ($server): array => [
                'uuid' => (string) $server->uuid,
                'name' => (string) $server->name,
                'containers' => array_values($this->serverContainers[$server->id] ?? []),
            ])
            ->values()
            ->all();
    }

    public function render()
    {
        return view('livewire.project.shared.logs', [
            'targets' => $this->containersLoaded ? $this->selectionTargets() : null,
        ]);
    }
}
