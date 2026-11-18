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
use App\Support\ContainerLogArchive;
use App\Support\RuntimeLogTargets;
use App\Traits\ListensToTeamChannel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

class Logs extends Component
{
    use ListensToTeamChannel;

    public ?string $type = null;

    public Application|Service|StandalonePostgresql|StandaloneRedis|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse|StandaloneSqlite $resource;

    public Collection $servers;

    public Collection $containers;

    #[Locked]
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

    /**
     * Sentinel log archives of removed containers, newest first.
     *
     * @var list<array{server: string, file: string, container: string, id: string, modifiedAt: int}>
     */
    #[Locked]
    public array $archives = [];

    /** `<server uuid>/<archive file>` of the selected removed container. */
    #[Url(as: 'archive', except: '')]
    public string $archive = '';

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
            $archives = [];
            foreach ($this->servers as $server) {
                unset($this->serverErrors[$server->id]);
                $this->serverContainers[$server->id] = $this->getContainersForServer($server);
                $archives = [...$archives, ...$this->getArchivesForServer($server, $this->serverContainers[$server->id])];
            }
            usort($archives, fn (array $left, array $right): int => $right['modifiedAt'] <=> $left['modifiedAt']);
            $this->archives = $archives;
            $this->containersLoaded = true;
            $this->syncLogSelection();
            $selectedArchive = $this->selectedArchive($this->selectionTargets()['sources'] !== []);
            $this->archive = $selectedArchive ? $selectedArchive['server'].'/'.$selectedArchive['file'] : '';
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

    /**
     * @param  array<int|string, mixed>  $containers
     * @return list<array{server: string, file: string, container: string, id: string, modifiedAt: int}>
     */
    private function getArchivesForServer($server, array $containers): array
    {
        $command = ContainerLogArchive::listCommand((string) $this->resource->uuid);
        if ($command === null || ! $server->isFunctional() || $server->isSwarm()) {
            return [];
        }

        try {
            $output = (string) instant_remote_process([$command], $server, false);
        } catch (\Throwable) {
            return [];
        }

        $existingIds = collect($containers)->map(fn ($container): string => (string) data_get($container, 'ID'))->filter()->values()->all();

        return ContainerLogArchive::parseList($output, (string) $server->uuid, $existingIds);
    }

    /**
     * The chosen archive, or the newest one when no container exists anymore.
     *
     * @return array{server: string, file: string, container: string, id: string, modifiedAt: int}|null
     */
    private function selectedArchive(bool $hasContainers): ?array
    {
        foreach ($this->archives as $entry) {
            if ($this->archive === $entry['server'].'/'.$entry['file']) {
                return $entry;
            }
        }

        return $hasContainers ? null : ($this->archives[0] ?? null);
    }

    /**
     * @return list<array{value: string, label: string, description?: string}>
     */
    private function archiveOptions(bool $hasContainers): array
    {
        $options = $hasContainers ? [['value' => '', 'label' => 'Current containers']] : [];
        foreach ($this->archives as $entry) {
            $options[] = [
                'value' => $entry['server'].'/'.$entry['file'],
                'label' => $entry['container'],
                'description' => 'Removed. Last log '.Carbon::createFromTimestamp($entry['modifiedAt'])->diffForHumans().'.',
            ];
        }

        return $options;
    }

    /**
     * Servers of the resource that do not keep the logs of removed containers, which the user can change.
     */
    private function archiveOffServers(): Collection
    {
        return $this->servers
            ->filter(fn ($server): bool => $server->isSentinelEnabled()
                && ! $server->settings->is_container_log_archive_enabled
                && (auth()->user()?->can('update', $server) ?? false))
            ->values();
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
     *     deployments: list<array{value: string, label: string, dot: string, state?: string}>,
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
        $targets = $this->containersLoaded ? $this->selectionTargets() : null;
        $hasContainers = ($targets['sources'] ?? []) !== [];
        $selectedArchive = $this->containersLoaded ? $this->selectedArchive($hasContainers) : null;

        return view('livewire.project.shared.logs', [
            'targets' => $targets,
            'selectedArchive' => $selectedArchive,
            'archiveServer' => $selectedArchive ? $this->servers->firstWhere('uuid', $selectedArchive['server']) : null,
            'archiveOptions' => $this->archiveOptions($hasContainers),
            'archiveOffServers' => $this->containersLoaded ? $this->archiveOffServers() : collect(),
        ]);
    }
}
