<?php

namespace App\Livewire\Project\ClusterStack;

use App\Actions\Node\DeleteNodeVolumeData;
use App\Enums\NodeVolumeCopyState;
use App\Jobs\DeleteNodeStackJob;
use App\Jobs\DeployNodeStackJob;
use App\Models\NodeContainer;
use App\Models\NodeStack;
use App\Models\NodeVolume;
use App\Models\NodeVolumeMount;
use App\Models\NodeWorkload;
use App\Models\Project;
use App\Services\NodeStack\NodeStackCompose;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** A stack of Compose services on a cluster (decision 0023): its services, shared volumes (decision 0024), Deploy, and Delete. */
class Show extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public Project $project;

    #[Locked]
    public NodeStack $stack;

    #[Locked]
    public string $environmentUuid;

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
    }

    public function deploy(): void
    {
        $this->authorize('deploy', $this->stack);
        if (NodeStackCompose::mountsContainerSocket($this->stack->compose_parsed ?? []) && ! auth()->user()->isAdminOfTeam((int) $this->stack->team_id)) {
            $this->dispatch('error', 'This stack mounts the Docker socket, which controls every container on its server. Only a team admin or owner can deploy it.');

            return;
        }
        if ($this->stack->deploy_status === 'deploying') {
            $this->dispatch('error', 'This stack is deploying. Wait until the deployment finishes.');

            return;
        }
        $this->stack->update(['deploy_status' => 'queued', 'deploy_error' => null]);
        DeployNodeStackJob::dispatch($this->stack->id, auth()->id());
        $this->dispatch('success', 'Stack deployment queued. Each service deploys after the services it depends on.');
    }

    public function delete(): mixed
    {
        $this->authorize('delete', $this->stack);
        DeleteNodeStackJob::dispatch($this->stack->id, auth()->id());

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
        } catch (\Throwable $exception) {
            handleError($exception, $this);
        }
    }

    public function render(): View
    {
        $this->stack->refresh();
        $workloads = NodeWorkload::query()
            ->where('node_stack_id', $this->stack->id)
            ->with(['nodes:id,uuid,name', 'revisions' => fn ($query) => $query->latest('id')->limit(1)])
            ->orderBy('id')
            ->get();
        $containers = NodeContainer::query()
            ->whereIn('node_workload_id', $workloads->pluck('id'))
            ->where('is_managed', true)
            ->get()
            ->groupBy('node_workload_id');
        $dependencies = $this->stack->dependencies();

        $volumes = $this->stack->volumes()
            ->with(['node', 'copies', 'mounts.workload' => fn ($workloads) => $workloads->withoutGlobalScopes()])
            ->get();

        $changes = $this->stack->compose_changes;

        return view('livewire.project.cluster-stack.show', [
            'composeChanges' => $changes === null ? null : [
                'commit_sha' => (string) ($changes['commit_sha'] ?? ''),
                'parts' => array_values(array_filter([
                    ['label' => 'added', 'services' => $changes['added'] ?? []],
                    ['label' => 'changed', 'services' => $changes['changed'] ?? []],
                    ['label' => 'removed', 'services' => $changes['removed'] ?? []],
                ], fn (array $part): bool => $part['services'] !== [])),
                'kept_volumes' => $changes['kept_volumes'] ?? [],
            ],
            'volumes' => $volumes->map(fn (NodeVolume $volume): array => [
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
            ])->all(),
            'services' => $workloads->map(function (NodeWorkload $workload) use ($containers, $dependencies): array {
                $container = $containers->get($workload->id)?->sortByDesc(fn (NodeContainer $container): int => $container->state === 'running' ? 1 : 0)->first();
                $statusType = match (true) {
                    $container === null => 'neutral',
                    $container->health_status === 'unhealthy' => 'error',
                    $container->state === 'running' => $container->health_status === 'starting' ? 'warning' : 'success',
                    $container->exit_code === 0 => 'success',
                    default => 'error',
                };

                return [
                    'uuid' => $workload->uuid,
                    'service' => $workload->stack_service,
                    'name' => $workload->name,
                    'image' => $workload->revisions->first()?->image,
                    'build' => $workload->buildsFromGit()
                        ? '/'.ltrim(trim((string) $workload->base_directory, '/').'/'.($workload->dockerfile_location ?: 'Dockerfile'), '/').(filled($workload->build_target) ? " (target {$workload->build_target})" : '')
                        : null,
                    'server' => $workload->nodes->first()?->name,
                    'mountsContainerSocket' => $workload->mountsContainerSocket(),
                    'status' => $container === null ? 'not deployed' : trim($container->state.' '.($container->health_status && $container->health_status !== 'unknown' ? "({$container->health_status})" : '')),
                    'statusType' => $statusType,
                    'domains' => $workload->domains ?? [],
                    'depends_on' => $dependencies[$workload->stack_service] ?? [],
                    'href' => route('project.cluster-application.show', [
                        'project_uuid' => $this->project->uuid,
                        'environment_uuid' => $this->environmentUuid,
                        'workload_uuid' => $workload->uuid,
                    ]),
                ];
            })->all(),
        ]);
    }
}
