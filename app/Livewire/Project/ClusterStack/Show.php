<?php

namespace App\Livewire\Project\ClusterStack;

use App\Actions\Node\DeleteNodeVolumeData;
use App\Actions\Node\DeployNodeStack;
use App\Actions\Node\ManageNodeDatabaseBackups;
use App\Actions\Node\QueueNodeWorkloadLifecycle;
use App\Actions\Node\SyncNodeStackCompose;
use App\Actions\Node\UpdateNodeWorkloadSettings;
use App\Enums\NodeVolumeCopyState;
use App\Enums\NodeWorkloadAction;
use App\Jobs\DeleteNodeStackJob;
use App\Models\NodeContainer;
use App\Models\NodeStack;
use App\Models\NodeStackDeployment;
use App\Models\NodeVolume;
use App\Models\NodeVolumeMount;
use App\Models\NodeWorkload;
use App\Models\Project;
use App\Models\ScheduledVolumeBackup;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;
use Throwable;

/**
 * A stack of Compose services on a cluster (decision 0023), with the pages of a v4 service: General
 * with the Compose file and its resources, Domains, Environment Variables, Persistent Storage,
 * Backups, Runtime Logs, Terminal, Scheduled Tasks, Webhooks, Deployments, and Danger Zone. The
 * pages that belong to one service show a block for each service, like the v4 Persistent Storage page.
 */
class Show extends Component
{
    use AuthorizesRequests;

    /** The route name suffix of each page. */
    public const SECTIONS = ['domains', 'environment-variables', 'storages', 'backups', 'logs', 'terminal', 'scheduled-tasks', 'webhooks', 'deployments', 'danger'];

    #[Locked]
    public Project $project;

    #[Locked]
    public NodeStack $stack;

    #[Locked]
    public string $environmentUuid;

    #[Locked]
    public string $section = 'general';

    public string $name = '';

    public string $composeRaw = '';

    /** The v4 Git options of a Git stack (decision 0033). Its services build with them. */
    public bool $isGitSubmodulesEnabled = true;

    public bool $isGitLfsEnabled = true;

    public bool $isGitShallowCloneEnabled = true;

    public function mount(string $project_uuid, string $environment_uuid, string $stack_uuid): void
    {
        abort_unless(nodesEnabled(), 404);
        $this->project = Project::query()->where('team_id', currentTeam()->id)->where('uuid', $project_uuid)->firstOrFail();
        $environment = $this->project->environments()->where('uuid', $environment_uuid)->firstOrFail();
        $this->stack = NodeStack::query()
            ->where('team_id', currentTeam()->id)
            ->where('project_id', $this->project->id)
            ->where('environment_id', $environment->id)
            ->where('uuid', $stack_uuid)
            ->firstOrFail();
        $this->authorize('view', $this->stack);
        $this->environmentUuid = $environment->uuid;
        $section = str((string) request()->route()?->getName())->after('project.cluster-stack.')->toString();
        $this->section = in_array($section, self::SECTIONS, true) ? $section : 'general';
        $this->name = $this->stack->name;
        $this->loadGitOptions();
        // The Compose file can hold secrets, like a v4 service: only users who may change the stack see it.
        $this->composeRaw = auth()->user()->can('update', $this->stack) ? (string) $this->stack->compose_raw : '';
    }

    public function deploy(): void
    {
        $this->authorize('deploy', $this->stack);
        try {
            DeployNodeStack::run($this->stack, auth()->user());
        } catch (RuntimeException $exception) {
            $this->dispatch('error', $exception->getMessage());

            return;
        }
        $this->dispatch('success', 'Stack deployment queued. Each service deploys after the services it depends on.');
    }

    public function restart(): void
    {
        $this->lifecycle(NodeWorkloadAction::RESTART);
    }

    public function stop(): void
    {
        $this->lifecycle(NodeWorkloadAction::STOP);
    }

    /** Restarts one service of the stack, from the Compose resources table. */
    public function restartService(string $workloadUuid): void
    {
        $this->authorize('deploy', $this->stack);
        $workload = $this->stack->workloads()->where('uuid', $workloadUuid)->firstOrFail();
        try {
            $result = QueueNodeWorkloadLifecycle::run($workload, NodeWorkloadAction::RESTART, auth()->user());
        } catch (RuntimeException $exception) {
            $this->dispatch('error', $exception->getMessage());

            return;
        }
        $result['operations'] === []
            ? $this->dispatch('error', implode(' ', $result['failures']) ?: 'No server accepted the restart.')
            : $this->dispatch('success', "Restart of {$workload->stack_service} queued.");
    }

    public function saveName(): void
    {
        $this->authorize('update', $this->stack);
        $this->validate(['name' => ['required', 'string', 'min:1', 'max:255']]);
        $this->stack->update(['name' => trim($this->name)]);
        $this->dispatch('success', 'Stack saved.');
    }

    /** Saves the Git options of a Git stack, like the v4 Advanced > Git settings. */
    public function saveGitOptions(): void
    {
        try {
            $this->authorize('update', $this->stack);
            UpdateNodeWorkloadSettings::make()->gitOptions($this->stack, [
                'is_git_submodules_enabled' => $this->isGitSubmodulesEnabled,
                'is_git_lfs_enabled' => $this->isGitLfsEnabled,
                'is_git_shallow_clone_enabled' => $this->isGitShallowCloneEnabled,
            ]);
            $this->dispatch('success', 'Git settings saved. The next deploy uses them.');
        } catch (Throwable $exception) {
            handleError($exception, $this);
        } finally {
            $this->loadGitOptions();
        }
    }

    private function loadGitOptions(): void
    {
        $this->isGitSubmodulesEnabled = (bool) $this->stack->is_git_submodules_enabled;
        $this->isGitLfsEnabled = (bool) $this->stack->is_git_lfs_enabled;
        $this->isGitShallowCloneEnabled = (bool) $this->stack->is_git_shallow_clone_enabled;
    }

    /** Saves a new Compose file of a stack without Git (decision 0029); the next deploy applies it. */
    public function saveCompose(): void
    {
        $this->authorize('update', $this->stack);
        $this->validate(['composeRaw' => ['required', 'string', 'max:262144']]);
        try {
            $changes = SyncNodeStackCompose::make()->edit($this->stack, $this->composeRaw, auth()->user());
        } catch (RuntimeException $exception) {
            $this->dispatch('error', $exception->getMessage());

            return;
        }
        $this->stack->refresh();
        $this->composeRaw = (string) $this->stack->compose_raw;
        $this->dispatch('success', $changes === null ? 'The Compose file did not change.' : 'Compose file saved. Deploy the stack to apply it.');
    }

    public function delete(bool $deleteVolumes = true): mixed
    {
        $this->authorize('delete', $this->stack);
        DeleteNodeStackJob::dispatch($this->stack->id, auth()->id(), $deleteVolumes);

        return redirectRoute($this, 'project.resource.index', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environmentUuid,
        ]);
    }

    /** Deletes a shared volume that no service of the stack mounts anymore (decision 0024). */
    public function deleteVolume(string $volumeUuid): void
    {
        $this->authorize('update', $this->stack);
        try {
            $volume = NodeVolume::query()
                ->where('node_stack_id', $this->stack->id)
                ->where('team_id', $this->stack->team_id)
                ->where('uuid', $volumeUuid)
                ->firstOrFail();
            DeleteNodeVolumeData::run($volume);
            $this->dispatch('success', 'Volume deleted.');
        } catch (Throwable $exception) {
            handleError($exception, $this);
        }
    }

    public function render(): View
    {
        $this->stack->refresh();
        $workloads = NodeWorkload::query()
            ->where('node_stack_id', $this->stack->id)
            ->with(['nodes', 'revisions' => fn ($query) => $query->latest('id')->limit(1)])
            ->withCount('scheduled_tasks')
            ->orderBy('id')
            ->get();
        $routeParameters = [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environmentUuid,
            'stack_uuid' => $this->stack->uuid,
        ];
        $status = $this->stack->statusSummary();

        return view('livewire.project.cluster-stack.show', [
            'routeParameters' => $routeParameters,
            'status' => $status,
            'isRunning' => ! str_starts_with($status, 'exited'),
            'services' => $this->services($workloads),
            'workloads' => $workloads,
            'composeChanges' => $this->composeChanges(),
            ...match ($this->section) {
                'general' => [],
                'storages' => ['volumes' => $this->volumes()],
                'deployments' => ['deployments' => $this->deployments()],
                default => [],
            },
        ]);
    }

    /**
     * One row for each service, like the v4 Compose resources table.
     *
     * @param  Collection<int, NodeWorkload>  $workloads
     * @return list<array<string, mixed>>
     */
    private function services(Collection $workloads): array
    {
        $containers = NodeContainer::query()
            ->whereIn('node_workload_id', $workloads->pluck('id'))
            ->where('is_managed', true)
            ->get()
            ->groupBy('node_workload_id');
        $dependencies = $this->stack->dependencies();

        return $workloads->map(function (NodeWorkload $workload) use ($containers, $dependencies): array {
            $container = $containers->get($workload->id)?->sortByDesc(fn (NodeContainer $container): int => $container->state === 'running' ? 1 : 0)->first();
            $statusType = match (true) {
                $container === null => 'neutral',
                $container->health_status === 'unhealthy' => 'error',
                $container->state === 'running' => $container->health_status === 'starting' ? 'warning' : 'success',
                $container->exit_code === 0 => 'success',
                default => 'error',
            };
            $serviceParameters = [
                'project_uuid' => $this->project->uuid,
                'environment_uuid' => $this->environmentUuid,
                'workload_uuid' => $workload->uuid,
            ];

            return [
                'uuid' => $workload->uuid,
                'service' => $workload->stack_service,
                'name' => $workload->name,
                'isDatabase' => $workload->isDatabase(),
                'image' => $workload->revisions->first()?->image,
                'build' => $workload->buildsFromGit()
                    ? '/'.ltrim(trim((string) $workload->base_directory, '/').'/'.($workload->dockerfile_location ?: 'Dockerfile'), '/').(filled($workload->build_target) ? " (target {$workload->build_target})" : '')
                    : null,
                'server' => $workload->nodes->first()?->name,
                'servers' => $workload->nodes,
                'mountsContainerSocket' => $workload->mountsContainerSocket(),
                'running' => $container?->state === 'running',
                'status' => $container === null ? 'not deployed' : trim($container->state.' '.($container->health_status && $container->health_status !== 'unknown' ? "({$container->health_status})" : '')),
                'statusType' => $statusType,
                'domains' => $workload->domains ?? [],
                'depends_on' => $dependencies[$workload->stack_service] ?? [],
                'href' => route('project.cluster-application.show', $serviceParameters),
                'domainsHref' => route('project.cluster-application.domains', $serviceParameters),
                'storageHref' => route('project.cluster-application.storage', $serviceParameters),
                'backupsHref' => route('project.cluster-application.backups', $serviceParameters),
                'terminalHref' => route('project.cluster-application.terminal', $serviceParameters),
                'tasksHref' => route('project.cluster-application.scheduled-tasks', $serviceParameters),
                'scheduledTasks' => (int) $workload->scheduled_tasks_count,
                ...($this->section === 'storages' ? ['mounts' => $this->mounts($workload)] : []),
                ...($this->section === 'backups' ? ['backupSchedules' => $this->backupSchedules($workload)] : []),
            ];
        })->all();
    }

    /** @return list<array{name: string, path: string, readOnly: bool, shared: bool}> */
    private function mounts(NodeWorkload $workload): array
    {
        $own = $workload->volumes()->whereNull('detached_at')->get()
            ->map(fn (NodeVolume $volume): array => ['name' => $volume->name, 'path' => (string) $volume->mount_path, 'readOnly' => false, 'shared' => false]);
        $shared = $workload->stackVolumes()->get()
            ->map(fn (NodeVolume $volume): array => ['name' => $volume->name, 'path' => (string) $volume->pivot->mount_path, 'readOnly' => (bool) $volume->pivot->read_only, 'shared' => true]);

        return $own->concat($shared)->sortBy('path')->values()->all();
    }

    /** The volume backup schedules of the volumes of a service, and the dump schedules of a database. */
    private function backupSchedules(NodeWorkload $workload): int
    {
        $volumes = $workload->volumes()->whereNull('detached_at')->pluck('id')->merge($workload->stackVolumes()->pluck('node_volumes.id'));
        $count = ScheduledVolumeBackup::query()->where('backupable_type', (new NodeVolume)->getMorphClass())->whereIn('backupable_id', $volumes)->count();

        return $count + ($workload->isDatabase() ? ManageNodeDatabaseBackups::schedulesOf($workload)->count() : 0);
    }

    /** @return ?array{commit_sha: string, parts: list<array{label: string, services: list<string>}>, kept_volumes: list<string>} */
    private function composeChanges(): ?array
    {
        $changes = $this->stack->compose_changes;

        return $changes === null ? null : [
            'commit_sha' => (string) ($changes['commit_sha'] ?? ''),
            'parts' => array_values(array_filter([
                ['label' => 'added', 'services' => $changes['added'] ?? []],
                ['label' => 'changed', 'services' => $changes['changed'] ?? []],
                ['label' => 'removed', 'services' => $changes['removed'] ?? []],
            ], fn (array $part): bool => $part['services'] !== [])),
            'kept_volumes' => $changes['kept_volumes'] ?? [],
        ];
    }

    /** @return list<array<string, mixed>> The volumes that several services share (decision 0024). */
    private function volumes(): array
    {
        return $this->stack->volumes()
            ->with(['node', 'copies', 'mounts.workload' => fn ($workloads) => $workloads->withoutGlobalScopes()])
            ->get()
            ->map(fn (NodeVolume $volume): array => [
                'uuid' => $volume->uuid,
                'name' => $volume->name,
                'server' => $volume->node?->name,
                'status' => $volume->inventoryStatus($volume->node?->freshVolumeInventory()),
                'receiving' => $volume->copies->contains('state', NodeVolumeCopyState::RECEIVING),
                'mounts' => $volume->mounts->sortBy('node_workload_id')->map(fn (NodeVolumeMount $mount): array => [
                    'service' => $mount->workload?->stack_service,
                    'path' => $mount->mount_path,
                    'readOnly' => $mount->read_only,
                ])->values()->all(),
            ])->all();
    }

    /** @return list<array<string, mixed>> The last deploys of the stack. */
    private function deployments(): array
    {
        return $this->stack->deployments()->with('requestedBy:id,name')->limit(20)->get()->map(fn (NodeStackDeployment $deployment): array => [
            'uuid' => $deployment->uuid,
            'status' => $deployment->status,
            'statusType' => match ($deployment->status) {
                NodeStackDeployment::DEPLOYED => 'success',
                NodeStackDeployment::FAILED => 'error',
                default => 'warning',
            },
            'commit' => filled($deployment->commit_sha) ? substr($deployment->commit_sha, 0, 7) : null,
            'by' => $deployment->requestedBy?->name ?? ($deployment->requested_by_id === null ? 'Automatic' : null),
            'createdAt' => $deployment->created_at,
            'duration' => $deployment->finished_at ? calculateDuration($deployment->created_at, $deployment->finished_at) : null,
            'error' => $deployment->error,
        ])->all();
    }

    private function lifecycle(NodeWorkloadAction $action): void
    {
        $this->authorize('deploy', $this->stack);
        ['queued' => $queued, 'failures' => $failures] = QueueNodeWorkloadLifecycle::forStack($this->stack, $action, auth()->user());
        $label = str($action->value)->title();
        if (! $queued) {
            $this->dispatch('error', "No service accepted the {$action->value}.".($failures === [] ? '' : ' '.implode(' ', $failures)));

            return;
        }
        $this->dispatch('success', "{$label} of {$this->stack->name} queued.".($failures === [] ? '' : ' Some services refused it: '.implode(' ', $failures)));
    }
}
