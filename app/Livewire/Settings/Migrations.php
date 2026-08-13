<?php

namespace App\Livewire\Settings;

use App\Actions\Migration\CloneResourceToDestination;
use App\Actions\Migration\DetectIndependentCoolifyInstall;
use App\Actions\Migration\DiscoverResources;
use App\Actions\Migration\RunInstanceMigration;
use App\Enums\InstanceMigrationStatus;
use App\Models\Application;
use App\Models\InstanceMigration;
use App\Models\PrivateKey;
use App\Models\ResourceMigration;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use RuntimeException;
use Throwable;

class Migrations extends Component
{
    use AuthorizesRequests;

    public string $mode = 'resources';

    public string $sourceServerUuid = '';

    public string $targetServerUuid = '';

    public bool $cloneVolumeData = true;

    /** @var list<string> */
    public array $selectedResourceUuids = [];

    /** @var list<array<string, mixed>> */
    public array $discoveredResources = [];

    /** @var list<array<string, mixed>> */
    public array $items = [];

    public int $migratedCount = 0;

    public int $failedCount = 0;

    public int $skippedCount = 0;

    public string $phase = 'idle';

    public string $targetBlockReason = '';

    /** @var list<array{uuid: string, name: string, ip: string}> */
    public array $servers = [];

    public string $instanceTargetIp = '';

    public string $instanceTargetUser = 'root';

    public int $instanceTargetPort = 22;

    public string $instancePrivateKeyId = '';

    public bool $instanceDryRun = false;

    public string $instanceStatus = '';

    public string $instanceDashboardUrl = '';

    public string $instanceError = '';

    public ?int $instanceMigrationId = null;

    public int $instanceProgress = 0;

    public bool $instanceMigrationRunning = false;

    /** @var list<array{status: string, label: string, state: string, note: ?string}> */
    public array $instanceSteps = [];

    /** @var list<array{status: string, note: ?string, at: string}> */
    public array $instancePhases = [];

    /** @var list<array{id: int, name: string}> */
    public array $privateKeys = [];

    public function mount(): mixed
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('dashboard');
        }

        $this->loadServers();
        $this->loadPrivateKeys();
        $this->restoreActiveInstanceMigration();

        return null;
    }

    public function setMode(string $mode): void
    {
        if (! in_array($mode, ['resources', 'instance'], true)) {
            return;
        }
        $this->mode = $mode;
    }

    public function updatedSourceServerUuid(): void
    {
        $this->discoverResources();
        $this->inspectTargetServer();
    }

    public function updatedTargetServerUuid(): void
    {
        $this->inspectTargetServer();
    }

    public function discoverResources(): void
    {
        $this->authorize('create', ResourceMigration::class);
        $this->discoveredResources = [];
        $this->selectedResourceUuids = [];
        $this->items = [];
        $this->phase = 'idle';

        if ($this->sourceServerUuid === '') {
            return;
        }

        $server = $this->serverByUuid($this->sourceServerUuid);
        if (! $server) {
            $this->dispatch('error', 'Source server was not found.');

            return;
        }

        $this->discoveredResources = DiscoverResources::run(currentTeam()->id, $server->uuid);
        $this->selectedResourceUuids = collect($this->discoveredResources)->pluck('uuid')->all();
        $this->phase = $this->discoveredResources === [] ? 'idle' : 'discovered';
    }

    public function toggleSelectAll(): void
    {
        $uuids = collect($this->discoveredResources)->pluck('uuid')->all();
        $this->selectedResourceUuids = count($this->selectedResourceUuids) === count($uuids)
            ? []
            : $uuids;
    }

    public function startMigration(): void
    {
        $this->authorize('create', ResourceMigration::class);
        $this->validate([
            'sourceServerUuid' => ['required', 'string'],
            'targetServerUuid' => ['required', 'string', 'different:sourceServerUuid'],
        ], [
            'targetServerUuid.different' => 'Choose a different target server.',
        ]);

        try {
            $sourceServer = $this->serverByUuid($this->sourceServerUuid);
            $targetServer = $this->serverByUuid($this->targetServerUuid);
            if (! $sourceServer || ! $targetServer) {
                throw new RuntimeException('Source or target server was not found.');
            }
            $this->inspectTargetServer();
            if ($this->targetBlockReason !== '') {
                throw new RuntimeException($this->targetBlockMessage());
            }

            $destination = $this->destinationFor($targetServer);
            if ($this->discoveredResources === []) {
                $this->discoverResources();
            }
            if ($this->selectedResourceUuids === []) {
                $this->selectedResourceUuids = collect($this->discoveredResources)->pluck('uuid')->all();
            }

            $selected = $this->orderedSelectedResources();
            if ($selected === []) {
                $this->dispatch('error', 'No resources found on the source server.');

                return;
            }

            $skipDatabaseUuids = $this->linkedDatabaseUuids($selected);
            $this->resetCounts();
            $this->items = [];
            $this->phase = 'running';

            foreach ($selected as $resource) {
                $uuid = (string) $resource['uuid'];
                $name = (string) ($resource['name'] ?? $uuid);
                if (in_array($uuid, $skipDatabaseUuids, true)) {
                    $this->skippedCount++;
                    $this->items[] = [
                        'name' => $name,
                        'status' => 'skipped',
                        'error' => 'Cloned with its linked application.',
                    ];

                    continue;
                }

                try {
                    $model = getResourceByUuid($uuid, currentTeam()->id);
                    if (! $model) {
                        throw new RuntimeException('Resource not found.');
                    }
                    $this->authorize('update', $model);
                    CloneResourceToDestination::run($model, $destination, $this->cloneVolumeData);
                    $this->migratedCount++;
                    $this->items[] = [
                        'name' => $name,
                        'status' => 'migrated',
                        'error' => '',
                    ];
                } catch (Throwable $exception) {
                    $this->failedCount++;
                    $this->items[] = [
                        'name' => $name,
                        'status' => 'failed',
                        'error' => $exception->getMessage(),
                    ];
                }
            }

            $this->phase = $this->failedCount > 0
                ? ($this->migratedCount > 0 ? 'partial' : 'failed')
                : 'completed';

            if ($this->phase === 'failed') {
                $this->dispatch('error', 'Migration failed.');

                return;
            }

            $this->dispatch('success', $this->phase === 'completed'
                ? 'Migration completed.'
                : 'Migration finished with partial success.');
        } catch (Throwable $exception) {
            $this->phase = 'failed';
            handleError($exception, $this);
        }
    }

    public function startInstanceMigration(): void
    {
        $this->authorize('create', InstanceMigration::class);
        $this->validate([
            'instanceTargetIp' => ['required', 'string'],
            'instanceTargetUser' => ['required', 'string'],
            'instanceTargetPort' => ['required', 'integer', 'min:1', 'max:65535'],
            'instancePrivateKeyId' => ['required', 'integer'],
        ]);

        if ($this->instanceMigrationRunning) {
            $this->dispatch('error', 'An instance migration is already running.');

            return;
        }

        $this->instanceError = '';
        $this->instanceDashboardUrl = '';
        $this->instancePhases = [];
        $this->instanceSteps = [];
        $this->instanceProgress = 0;
        $this->instanceStatus = InstanceMigrationStatus::Pending->value;
        $this->items = [];

        try {
            $key = PrivateKey::whereTeamId(currentTeam()->id)->where('id', (int) $this->instancePrivateKeyId)->first()
                ?? PrivateKey::whereTeamId(0)->where('id', (int) $this->instancePrivateKeyId)->first();
            if (! $key) {
                throw new RuntimeException('Private key was not found.');
            }

            $migration = InstanceMigration::create([
                'team_id' => currentTeam()->id,
                'status' => InstanceMigrationStatus::Pending,
                'target_ip' => $this->instanceTargetIp,
                'target_port' => $this->instanceTargetPort,
                'target_user' => $this->instanceTargetUser,
                'target_private_key_id' => $key->id,
                'dry_run' => $this->instanceDryRun,
                'created_by_user_id' => auth()->id(),
                'phases' => [],
                'items' => [],
            ]);

            $this->instanceMigrationId = $migration->id;
            $this->syncInstanceMigrationState($migration);

            RunInstanceMigration::dispatch($migration);

            $this->dispatch('success', $this->instanceDryRun
                ? 'Instance migration dry run queued. Progress updates below.'
                : 'Instance migration queued. Progress updates below.');
        } catch (Throwable $exception) {
            $this->instanceStatus = InstanceMigrationStatus::Failed->value;
            $this->instanceError = $exception->getMessage();
            handleError($exception, $this);
        }
    }

    public function refreshInstanceMigration(): void
    {
        if (! $this->instanceMigrationId || ! currentTeam()) {
            return;
        }

        $migration = InstanceMigration::query()
            ->where('team_id', currentTeam()->id)
            ->find($this->instanceMigrationId);

        if (! $migration) {
            return;
        }

        $wasRunning = $this->instanceMigrationRunning;
        $this->syncInstanceMigrationState($migration);

        if ($wasRunning && $migration->status->isTerminal()) {
            if ($migration->status === InstanceMigrationStatus::Failed) {
                $this->dispatch('error', $migration->error ?: 'Instance migration failed.');
            } else {
                $this->dispatch('success', $migration->dry_run
                    ? 'Instance migration dry run completed.'
                    : 'Instance migration completed.');
            }
        }
    }

    public function render()
    {
        return view('livewire.settings.migrations');
    }

    private function syncInstanceMigrationState(InstanceMigration $migration): void
    {
        $this->instanceMigrationId = $migration->id;
        $this->instanceStatus = $migration->status->value;
        $this->instanceMigrationRunning = ! $migration->status->isTerminal();
        $this->instancePhases = $migration->phases ?? [];
        $this->instanceSteps = $migration->stepStates();
        $this->instanceProgress = $migration->progressPercent();
        $this->instanceDashboardUrl = (string) ($migration->dashboard_url ?? '');
        $this->instanceError = (string) ($migration->error ?? '');
        $this->items = $migration->items ?? [];
        $this->instanceDryRun = (bool) $migration->dry_run;
        $this->instanceTargetIp = (string) $migration->target_ip;
        $this->instanceTargetUser = (string) $migration->target_user;
        $this->instanceTargetPort = (int) $migration->target_port;
        $this->instancePrivateKeyId = (string) $migration->target_private_key_id;
    }

    private function restoreActiveInstanceMigration(): void
    {
        if (! currentTeam()) {
            return;
        }

        $migration = InstanceMigration::query()
            ->where('team_id', currentTeam()->id)
            ->latest('id')
            ->first();

        if (! $migration) {
            return;
        }

        $this->syncInstanceMigrationState($migration);
        if (! $migration->status->isTerminal()) {
            $this->mode = 'instance';
        }
    }

    private function loadServers(): void
    {
        if (! currentTeam()) {
            return;
        }

        $this->servers = Server::ownedByCurrentTeam()
            ->get()
            ->reject(fn (Server $server): bool => $server->isBuildServer())
            ->map(fn (Server $server): array => [
                'uuid' => $server->uuid,
                'name' => $server->name,
                'ip' => $server->ip,
            ])
            ->values()
            ->all();
    }

    private function loadPrivateKeys(): void
    {
        if (! currentTeam()) {
            return;
        }

        $this->privateKeys = PrivateKey::whereIn('team_id', [currentTeam()->id, 0])
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (PrivateKey $key): array => [
                'id' => $key->id,
                'name' => $key->name,
            ])
            ->values()
            ->all();
    }

    private function serverByUuid(string $uuid): ?Server
    {
        return Server::ownedByCurrentTeam()->where('uuid', $uuid)->first();
    }

    private function inspectTargetServer(): void
    {
        $this->targetBlockReason = '';
        if ($this->targetServerUuid === '') {
            return;
        }

        $targetServer = $this->serverByUuid($this->targetServerUuid);
        if (! $targetServer) {
            $this->targetBlockReason = 'not_found';

            return;
        }
        if ($this->sourceServerUuid !== '' && $this->targetServerUuid === $this->sourceServerUuid) {
            $this->targetBlockReason = 'same_server';

            return;
        }
        if (! $targetServer->canHostResources()) {
            $this->targetBlockReason = 'cannot_host';

            return;
        }
        if (! $targetServer->isFunctional()) {
            $this->targetBlockReason = 'not_ready';

            return;
        }
        if (! $this->destinationFor($targetServer, throwIfMissing: false)) {
            $this->targetBlockReason = 'no_destination';

            return;
        }
        if (DetectIndependentCoolifyInstall::run($targetServer)) {
            $this->targetBlockReason = 'independent_coolify';
        }
    }

    private function targetBlockMessage(): string
    {
        return match ($this->targetBlockReason) {
            'not_found' => 'Target server was not found.',
            'same_server' => 'Choose a different target server.',
            'cannot_host' => 'The selected target server cannot host resources.',
            'not_ready' => 'The target server is not reachable or not validated. Open Servers, validate it, then try again.',
            'no_destination' => 'The target server has no Docker destination.',
            'independent_coolify' => 'The target already has Coolify installed. Use a server added to this instance, not a second Coolify dashboard.',
            default => 'The target server cannot be used for migration.',
        };
    }

    private function destinationFor(Server $server, bool $throwIfMissing = true): StandaloneDocker|SwarmDocker|null
    {
        $destination = $server->destinations()->first(fn ($destination): bool => $destination instanceof StandaloneDocker || $destination instanceof SwarmDocker);
        if (! $destination && $throwIfMissing) {
            throw new RuntimeException('The target server has no Docker destination.');
        }

        return $destination;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function orderedSelectedResources(): array
    {
        $selected = array_values(array_filter(
            $this->discoveredResources,
            fn (array $resource): bool => in_array($resource['uuid'] ?? null, $this->selectedResourceUuids, true),
        ));

        usort($selected, function (array $left, array $right): int {
            return $this->typeRank((string) ($left['type'] ?? '')) <=> $this->typeRank((string) ($right['type'] ?? ''));
        });

        return $selected;
    }

    private function typeRank(string $type): int
    {
        return match (true) {
            str_starts_with($type, 'standalone') => 0,
            $type === 'service' => 1,
            default => 2,
        };
    }

    /**
     * @param  list<array<string, mixed>>  $selected
     * @return list<string>
     */
    private function linkedDatabaseUuids(array $selected): array
    {
        if (! $this->cloneVolumeData) {
            return [];
        }

        $selectedUuids = collect($selected)->pluck('uuid')->all();
        $linked = [];

        foreach ($selected as $resource) {
            if (($resource['type'] ?? null) !== 'application') {
                continue;
            }
            $application = getResourceByUuid((string) $resource['uuid'], currentTeam()->id);
            if (! $application instanceof Application) {
                continue;
            }
            foreach (find_linked_standalone_databases($application) as $database) {
                if (in_array($database->uuid, $selectedUuids, true)) {
                    $linked[] = $database->uuid;
                }
            }
        }

        return array_values(array_unique($linked));
    }

    private function resetCounts(): void
    {
        $this->migratedCount = 0;
        $this->failedCount = 0;
        $this->skippedCount = 0;
    }
}
