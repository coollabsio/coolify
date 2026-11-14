<?php

namespace App\Livewire\Project\ClusterStack;

use App\Jobs\DeleteNodeStackJob;
use App\Jobs\DeployNodeStackJob;
use App\Models\NodeContainer;
use App\Models\NodeStack;
use App\Models\NodeWorkload;
use App\Models\Project;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** A stack of Compose services on a cluster (decision 0023): its services, Deploy, and Delete. */
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
        $this->authorize('update', $this->stack);
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

        return view('livewire.project.cluster-stack.show', [
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
                    'server' => $workload->nodes->first()?->name,
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
