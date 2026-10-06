<?php

namespace App\Livewire\Node;

use App\Enums\NodeOperationStatus;
use App\Jobs\DeployNodeWorkloadJob;
use App\Jobs\ManageNodeWorkloadJob;
use App\Models\Node;
use App\Models\NodeOperation;
use App\Models\NodeWorkload;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Activity extends Component
{
    use AuthorizesRequests;

    public const PER_PAGE = 25;

    /** @var array<string, string> */
    public const STATUS_FILTERS = [
        'succeeded' => 'Succeeded',
        'failed' => 'Failed',
        'active' => 'Active',
    ];

    public Node $node;

    #[Locked]
    public int $page = 1;

    /** @var list<string> */
    #[Locked]
    public array $statusFilters = [];

    /** @var list<string> */
    #[Locked]
    public array $applicationFilters = [];

    public function mount(string $node_uuid): void
    {
        abort_unless(isDev() && config('constants.sentinel.host_enabled', false), 404);
        $this->node = Node::query()
            ->where('uuid', $node_uuid)
            ->where('team_id', currentTeam()->id)
            ->firstOrFail();
        $this->authorize('view', $this->node);
    }

    public function refreshActivity(): void
    {
        $this->authorize('view', $this->node);
        $this->dispatch('info', 'Activity refreshed.');
    }

    public function toggleStatusFilter(string $status): void
    {
        if (! array_key_exists($status, self::STATUS_FILTERS)) {
            return;
        }
        $this->statusFilters = $this->toggle($this->statusFilters, $status);
        $this->page = 1;
    }

    public function toggleApplicationFilter(string $workloadUuid): void
    {
        if (! $this->applicationOptionsQuery()->where('uuid', $workloadUuid)->exists()) {
            return;
        }
        $this->applicationFilters = $this->toggle($this->applicationFilters, $workloadUuid);
        $this->page = 1;
    }

    public function clearFilters(): void
    {
        $this->statusFilters = [];
        $this->applicationFilters = [];
        $this->page = 1;
    }

    public function goToPage(int $page): void
    {
        $this->page = max(1, $page);
    }

    public function previousPage(): void
    {
        $this->goToPage($this->page - 1);
    }

    public function nextPage(): void
    {
        $this->goToPage($this->page + 1);
    }

    /**
     * Sends an operation whose result is uncertain to Sentinel again with the same identity.
     */
    public function retryOperation(string $operationUuid): void
    {
        try {
            $this->authorize('update', $this->node);
            $operation = NodeOperation::query()
                ->where('node_id', $this->node->id)
                ->where('uuid', $operationUuid)
                ->whereIn('command_type', ['workload.deploy.v1', 'workload.lifecycle.v1'])
                ->where('status', NodeOperationStatus::UNCERTAIN)
                ->firstOrFail();
            match ($operation->command_type) {
                'workload.deploy.v1' => DeployNodeWorkloadJob::dispatch($operation->id),
                'workload.lifecycle.v1' => ManageNodeWorkloadJob::dispatch($operation->id),
            };
            $this->dispatch('success', 'Operation recovery queued.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function render(): View
    {
        $this->authorize('view', $this->node);
        $total = $this->operationsQuery()->count();
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $this->page = min(max(1, $this->page), $lastPage);
        $skip = ($this->page - 1) * self::PER_PAGE;
        $operations = $this->operationsQuery()
            ->with(['workload.environment.project', 'requestedBy'])
            ->latest('id')
            ->skip($skip)
            ->take(self::PER_PAGE)
            ->get();
        $moveTargetNames = $this->moveTargetNames($operations->map->moveTargetUuid()->filter()->unique()->values()->all());

        return view('livewire.node.activity', [
            'rows' => $operations->map(fn (NodeOperation $operation): array => $this->row($operation, $moveTargetNames))->all(),
            'applicationOptions' => $this->applicationOptionsQuery()->orderBy('name')->get(['uuid', 'name'])
                ->map(fn (NodeWorkload $workload): array => ['value' => $workload->uuid, 'label' => $workload->name])
                ->all(),
            'statusOptions' => collect(self::STATUS_FILTERS)
                ->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])
                ->values()
                ->all(),
            'hasActiveFilter' => $this->statusFilters !== [] || $this->applicationFilters !== [],
            'pagination' => [
                'total' => $total,
                'currentPage' => $this->page,
                'lastPage' => $lastPage,
                'from' => $total === 0 ? 0 : $skip + 1,
                'to' => min($skip + $operations->count(), $total),
            ],
        ]);
    }

    /**
     * Operations of this server that people care about. Background commands only appear when they fail.
     */
    private function operationsQuery(): Builder
    {
        return NodeOperation::query()
            ->where('node_id', $this->node->id)
            ->userFacing()
            ->when($this->statusFilters !== [], fn (Builder $query) => $query->whereIn('status', $this->filteredStatuses()))
            ->when($this->applicationFilters !== [], fn (Builder $query) => $query->whereHas('workload', fn (Builder $workloads) => $workloads
                ->where('team_id', $this->node->team_id)
                ->whereIn('uuid', $this->applicationFilters)));
    }

    /** Applications with at least one operation on this server, including ones that moved away. */
    private function applicationOptionsQuery(): Builder
    {
        return NodeWorkload::query()
            ->where('team_id', $this->node->team_id)
            ->whereIn('id', NodeOperation::query()
                ->select('node_workload_id')
                ->where('node_id', $this->node->id)
                ->whereNotNull('node_workload_id'));
    }

    /** @return list<NodeOperationStatus> */
    private function filteredStatuses(): array
    {
        return collect($this->statusFilters)
            ->flatMap(fn (string $filter): array => match ($filter) {
                'succeeded' => [NodeOperationStatus::SUCCEEDED],
                'failed' => [NodeOperationStatus::FAILED, NodeOperationStatus::TIMED_OUT, NodeOperationStatus::UNCERTAIN],
                'active' => [NodeOperationStatus::QUEUED, NodeOperationStatus::DISPATCHED, NodeOperationStatus::RUNNING, NodeOperationStatus::VERIFYING],
                default => [],
            })
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $uuids
     * @return array<string, string>
     */
    private function moveTargetNames(array $uuids): array
    {
        if ($uuids === []) {
            return [];
        }

        return Node::query()
            ->where('team_id', $this->node->team_id)
            ->whereIn('uuid', $uuids)
            ->pluck('name', 'uuid')
            ->all();
    }

    /**
     * @param  array<string, string>  $moveTargetNames
     * @return array<string, mixed>
     */
    private function row(NodeOperation $operation, array $moveTargetNames): array
    {
        $workload = $operation->workload?->team_id === $this->node->team_id ? $operation->workload : null;
        $project = $workload?->environment?->project;
        $routeParameters = $project ? [
            'project_uuid' => $project->uuid,
            'environment_uuid' => $workload->environment->uuid,
            'workload_uuid' => $workload->uuid,
        ] : null;
        $requestedBy = $operation->requestedBy;

        return [
            'uuid' => $operation->uuid,
            'action' => $operation->actionLabel($moveTargetNames[$operation->moveTargetUuid()] ?? null),
            'actionHref' => $operation->isDeployment() && $routeParameters
                ? route('project.cluster-application.deployment.show', [...$routeParameters, 'deployment_uuid' => $operation->uuid])
                : null,
            'application' => $workload?->name,
            'applicationHref' => $routeParameters ? route('project.cluster-application.show', $routeParameters) : null,
            'status' => $operation->status->label(),
            'statusType' => $operation->status->badgeType(),
            'error' => $operation->error,
            'canRecover' => $operation->status === NodeOperationStatus::UNCERTAIN
                && in_array($operation->command_type, ['workload.deploy.v1', 'workload.lifecycle.v1'], true),
            'requestedBy' => $requestedBy ? ($requestedBy->name ?: $requestedBy->email) : 'Coolify',
            'createdAt' => $operation->created_at,
        ];
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function toggle(array $values, string $value): array
    {
        return in_array($value, $values, true)
            ? array_values(array_diff($values, [$value]))
            : [...$values, $value];
    }
}
