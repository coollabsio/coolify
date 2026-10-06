<?php

namespace App\Livewire\Node;

use App\Actions\Node\CreateDeploymentOperation;
use App\Actions\Node\CreateLifecycleOperation;
use App\Actions\Node\CreateMoveOperation;
use App\Actions\Node\DetermineWorkloadState;
use App\Actions\Node\FetchContainers;
use App\Actions\Node\FetchLatestSentinelRelease;
use App\Actions\Node\InstallSentinel;
use App\Actions\Node\PrepareNodeWorkloadRevision;
use App\Actions\Node\QueueNodeClusterNetworkRevision;
use App\Actions\Node\RepairFluxTrust;
use App\Actions\Node\UpgradeSentinel;
use App\Actions\Node\ValidateNode;
use App\Actions\Sentinel\FetchFluxNodeInformation;
use App\Actions\Sentinel\PingFluxConnection;
use App\Actions\Sentinel\RenewFluxCertificate;
use App\Enums\NodeOperationStatus;
use App\Enums\NodeWorkloadAction;
use App\Enums\NodeWorkloadState;
use App\Jobs\DeployNodeWorkloadJob;
use App\Jobs\ManageNodeWorkloadJob;
use App\Jobs\MoveNodeWorkloadJob;
use App\Jobs\UpgradeNodeSentinelJob;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\NodeOperation;
use App\Models\NodeWorkload;
use App\Models\NodeWorkloadRevision;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Show extends Component
{
    use AuthorizesRequests;

    /** @var list<string> */
    private const SECTIONS = ['general', 'workloads', 'containers', 'sentinel'];

    public Node $node;

    #[Locked]
    public string $section = 'general';

    /** @var array<string, mixed>|null */
    public ?array $fluxConnection = null;

    /** @var array{version: string, digest: string, image: string}|null */
    #[Locked]
    public ?array $sentinelRelease = null;

    /** @var array{uuid: string, status: string, version: ?string, error: ?string, updated_at: ?string}|null */
    #[Locked]
    public ?array $sentinelUpgrade = null;

    /** @var array<string, string> */
    public array $dnsNames = [];

    /** @var array<string, string> */
    public array $moveTargets = [];

    public function mount(string $node_uuid, ?string $section = null): void
    {
        abort_unless(isDev() && config('constants.sentinel.host_enabled', false), 404);
        $this->node = Node::query()
            ->where('uuid', $node_uuid)
            ->where('team_id', currentTeam()->id)
            ->firstOrFail();
        $this->authorize('view', $this->node);
        $this->section = $this->resolveSection($section);
        $this->loadSectionData();
    }

    public function installSentinel(): void
    {
        $this->runAction(fn () => InstallSentinel::run($this->node), 'Host Sentinel installed and started.');
    }

    public function upgradeSentinel(): void
    {
        $this->authorize('manageSentinel', $this->node);

        try {
            $operation = UpgradeSentinel::make()->start($this->node, auth()->user());
            UpgradeNodeSentinelJob::dispatch($operation->id);
            $this->dispatch('success', 'Sentinel upgrade queued.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
        $this->loadFluxConnection();
    }

    public function pollSentinelUpgrade(): void
    {
        $this->authorize('view', $this->node);
        $this->node->refresh();
        $this->loadFluxConnection();
    }

    public function restartSentinel(): void
    {
        $this->runAction(fn () => $this->node->restartSentinel(), 'Host Sentinel restarted.');
    }

    public function validateNode(): void
    {
        $this->runAction(fn () => ValidateNode::run($this->node), 'Server validation completed.');
        $this->node->refresh();
        $this->loadSectionData();
    }

    public function refreshInformation(): void
    {
        $this->runAction(fn () => FetchFluxNodeInformation::run($this->node), 'Server details refreshed through Flux.');
        $this->node->refresh();
        $this->loadSectionData();
    }

    public function refreshContainers(): void
    {
        try {
            $this->authorize('view', $this->node);
            $count = FetchContainers::run($this->node);
            $this->loadNodeData();
            $label = $count === 1 ? 'container' : 'containers';
            $this->dispatch('success', "Container inventory refreshed. {$count} {$label} found.");
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function deployRevision(string $revisionUuid): void
    {
        try {
            $this->authorize('update', $this->node);
            $revision = NodeWorkloadRevision::query()
                ->with('workload')
                ->where('uuid', $revisionUuid)
                ->whereHas('workload', fn ($query) => $query
                    ->where('team_id', $this->node->team_id)
                    ->whereHas('nodes', fn ($nodes) => $nodes->whereKey($this->node->id)))
                ->firstOrFail();
            if ($revision->workload->revisions()->latest('id')->value('id') === $revision->id) {
                $revision = PrepareNodeWorkloadRevision::run($revision->workload);
            }
            $deployment = CreateDeploymentOperation::run($this->node, $revision, auth()->user(), 'newer');
            $operation = $deployment['operation'];
            if ($deployment['created']) {
                DeployNodeWorkloadJob::dispatch($operation->id);
                $this->dispatch('success', 'Workload deployment queued.');
            } else {
                $this->dispatch('info', 'This workload already has an active operation.');
            }
            $this->loadNodeData();
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    /**
     * Restarts, stops, starts, or removes the container of an application on this server only.
     * The confirmation modals of each row call it with the workload uuid.
     */
    public function manageWorkload(string $actionValue, string $workloadUuid): void
    {
        try {
            $this->authorize('update', $this->node);
            $action = NodeWorkloadAction::from($actionValue);
            $revision = NodeWorkloadRevision::query()
                ->whereHas('workload', fn ($query) => $query
                    ->where('uuid', $workloadUuid)
                    ->where('team_id', $this->node->team_id)
                    ->whereHas('nodes', fn ($nodes) => $nodes->whereKey($this->node->id)))
                ->latest('id')
                ->firstOrFail();
            $operation = CreateLifecycleOperation::run($this->node, $revision, $action, auth()->user());
            ManageNodeWorkloadJob::dispatch($operation->id);
            match ($action) {
                NodeWorkloadAction::STOP => $this->dispatch('info', 'Gracefully stopping application.<br/>It could take a while depending on the application.'),
                NodeWorkloadAction::REMOVE => $this->dispatch('info', 'Removing the application container.'),
                default => $this->dispatch('success', str($action->value)->title().' command queued.'),
            };
            $this->loadNodeData();
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function refreshWorkloads(): void
    {
        $this->authorize('view', $this->node);
        $this->loadNodeData();
        $this->dispatch('info', 'Application state refreshed.');
    }

    public function saveWorkloadDnsName(string $workloadUuid): void
    {
        $field = 'dnsNames.'.$workloadUuid;

        try {
            $this->authorize('update', $this->node);
            $this->dnsNames[$workloadUuid] = strtolower(trim($this->dnsNames[$workloadUuid] ?? ''));
            $this->validate([
                $field => ['required', 'string', 'max:63', 'regex:/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/'],
            ], [
                $field.'.regex' => 'Use lowercase letters, numbers, and hyphens. Do not start or end with a hyphen.',
            ]);

            $workload = DB::transaction(function () use ($field, $workloadUuid): ?NodeWorkload {
                $workload = NodeWorkload::query()
                    ->where('uuid', $workloadUuid)
                    ->where('team_id', $this->node->team_id)
                    ->whereHas('nodes', fn ($query) => $query->whereKey($this->node->id))
                    ->lockForUpdate()
                    ->firstOrFail();
                $dnsName = $this->dnsNames[$workloadUuid];
                $hasCollision = $this->node->node_cluster_id !== null
                    && NodeWorkload::query()
                        ->whereKeyNot($workload->id)
                        ->where('internal_dns_name', $dnsName)
                        ->whereHas('nodes', fn ($query) => $query->where('node_cluster_id', $this->node->node_cluster_id))
                        ->exists();
                if ($hasCollision) {
                    throw ValidationException::withMessages([
                        $field => 'This internal DNS name is already in use in this mesh.',
                    ]);
                }

                if ($workload->internal_dns_name === $dnsName) {
                    return null;
                }
                $workload->update(['internal_dns_name' => $dnsName]);

                return $workload;
            });

            // Sentinel reads the name table from the cluster network revision, so no redeploy is needed.
            if ($workload !== null) {
                NodeCluster::query()
                    ->whereKey($workload->clusterIds())
                    ->get()
                    ->each(fn (NodeCluster $cluster) => QueueNodeClusterNetworkRevision::run($cluster, auth()->user()));
            }

            $this->loadNodeData();
            $this->dispatch('close-modal');
            $this->dispatch('success', 'Internal DNS name updated.');
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function moveWorkload(string $workloadUuid): void
    {
        try {
            $this->authorize('update', $this->node);
            $field = 'moveTargets.'.$workloadUuid;
            $this->validate([$field => ['required', 'string']]);
            $target = Node::query()
                ->where('uuid', $this->moveTargets[$workloadUuid])
                ->where('team_id', $this->node->team_id)
                ->where('node_cluster_id', $this->node->node_cluster_id)
                ->whereKeyNot($this->node->id)
                ->firstOrFail();
            $revision = NodeWorkloadRevision::query()
                ->whereHas('workload', fn ($query) => $query
                    ->where('uuid', $workloadUuid)
                    ->where('team_id', $this->node->team_id)
                    ->whereHas('nodes', fn ($nodes) => $nodes->whereKey($this->node->id)))
                ->latest('id')
                ->firstOrFail();
            $operation = CreateMoveOperation::run($this->node, $target, $revision, auth()->user());
            MoveNodeWorkloadJob::dispatch($operation->id);
            $this->dispatch('close-modal');
            $this->dispatch('success', 'Workload move queued. The source stays active until the target is ready.');
            $this->loadNodeData();
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function testFluxConnection(): void
    {
        try {
            $this->authorize('manageSentinel', $this->node);
            $result = PingFluxConnection::run($this->node);
            $this->dispatch('success', sprintf(
                'Flux connection test succeeded. Sentinel %s responded in %s ms.',
                data_get($result, 'sentinel_version', 'unknown'),
                data_get($result, 'latency_ms', 'unknown'),
            ));
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function repairFluxTrust(): void
    {
        $this->runAction(fn () => RepairFluxTrust::run($this->node), 'Sentinel Flux trust repaired.');
    }

    public function renewFluxCertificate(): void
    {
        $this->runAction(function (): void {
            $this->authorize('update', instanceSettings());
            RenewFluxCertificate::run(null, true);
        }, 'Flux TLS certificate renewed.');
    }

    public function refreshFluxConnection(): void
    {
        $this->authorize('view', $this->node);
        $this->loadFluxConnection();
        $this->dispatch('info', 'Flux connection state refreshed.');
    }

    public function render(): View
    {
        return view('livewire.node.show', $this->section === 'workloads' ? $this->workloadsViewData() : []);
    }

    /**
     * Builds the Applications rows with a fixed number of queries, however many applications run here.
     *
     * @return array{workloadRows: list<array<string, mixed>>, moveTargetOptions: list<array{value: string, label: string}>}
     */
    private function workloadsViewData(): array
    {
        $workloads = $this->node->workloads()
            ->where('node_workloads.team_id', $this->node->team_id)
            ->with([
                'environment.project',
                'revisions' => fn ($query) => $query->latest('id')->limit(1),
                'containers' => fn ($query) => $query->where('node_id', $this->node->id)->where('is_managed', true),
            ])
            ->orderBy('node_workloads.name')
            ->get();
        $latestOperations = $workloads->isEmpty() ? collect() : NodeOperation::query()
            ->whereIn('id', NodeOperation::query()
                ->selectRaw('max(id)')
                ->where('node_id', $this->node->id)
                ->whereIn('node_workload_id', $workloads->modelKeys())
                ->groupBy('node_workload_id'))
            ->get()
            ->keyBy('node_workload_id');

        $workloadRows = $workloads->map(function (NodeWorkload $workload) use ($latestOperations): array {
            $state = DetermineWorkloadState::make()->fromInventory(
                $this->node,
                fn () => $workload->revisions->first(),
                fn () => $workload->containers,
            );
            $project = $workload->environment?->project;
            $routeParameters = $project ? [
                'project_uuid' => $project->uuid,
                'environment_uuid' => $workload->environment->uuid,
                'workload_uuid' => $workload->uuid,
            ] : null;

            return [
                'workload' => $workload,
                'revision' => $workload->revisions->first(),
                'status' => str($state->value)->title()->toString(),
                'statusType' => $state->badgeType(),
                'isRunning' => $state === NodeWorkloadState::RUNNING,
                'containerPresent' => $workload->containers->isNotEmpty(),
                'href' => $routeParameters ? route('project.cluster-application.show', $routeParameters) : null,
                'lastActivity' => $this->lastActivity($latestOperations->get($workload->id), $routeParameters),
            ];
        })->values()->all();

        $moveTargetOptions = $this->node->node_cluster_id === null ? [] : Node::query()
            ->where('team_id', $this->node->team_id)
            ->where('node_cluster_id', $this->node->node_cluster_id)
            ->whereKeyNot($this->node->id)
            ->orderBy('name')
            ->get(['uuid', 'name'])
            ->map(fn (Node $target): array => ['value' => $target->uuid, 'label' => $target->name])
            ->all();

        return compact('workloadRows', 'moveTargetOptions');
    }

    /**
     * @param  array{project_uuid: string, environment_uuid: string, workload_uuid: string}|null  $routeParameters
     * @return array{text: string, tone: string, time: string, timestamp: string, href: ?string}|null
     */
    private function lastActivity(?NodeOperation $operation, ?array $routeParameters): ?array
    {
        if ($operation === null) {
            return null;
        }
        $summary = $operation->activitySummary();

        return [
            'text' => $summary['text'],
            'tone' => $summary['tone'],
            'time' => $summary['at']->diffForHumans(),
            'timestamp' => $summary['at']->toIso8601String(),
            'href' => match (true) {
                $operation->isDeployment() && $routeParameters !== null => route(
                    'project.cluster-application.deployment.show',
                    [...$routeParameters, 'deployment_uuid' => $operation->uuid],
                ),
                $operation->status === NodeOperationStatus::UNCERTAIN => route('node.activity', ['node_uuid' => $this->node->uuid]),
                default => null,
            },
        ];
    }

    private function resolveSection(?string $section): string
    {
        $section ??= match (request()->route()?->getName()) {
            'node.workloads' => 'workloads',
            'node.containers' => 'containers',
            'node.sentinel' => 'sentinel',
            default => 'general',
        };

        return in_array($section, self::SECTIONS, true) ? $section : 'general';
    }

    /**
     * Load only the data that the current section renders.
     */
    private function loadSectionData(): void
    {
        match ($this->section) {
            'workloads' => $this->loadNodeData(),
            'containers' => $this->node->load('containers'),
            'sentinel' => $this->loadFluxConnection(),
            default => $this->node->load('cluster'),
        };
    }

    private function loadFluxConnection(): void
    {
        $this->fluxConnection = Cache::get($this->node->cacheKey());
        $this->sentinelRelease = FetchLatestSentinelRelease::run();
        $operation = $this->node->operations()
            ->where('command_type', UpgradeSentinel::COMMAND_TYPE)
            ->latest('id')
            ->first();
        $this->sentinelUpgrade = $operation === null ? null : [
            'uuid' => $operation->uuid,
            'status' => $operation->status->value,
            'version' => data_get($operation->request, 'version'),
            'error' => $operation->error,
            'updated_at' => $operation->updated_at?->toIso8601String(),
        ];
    }

    private function loadNodeData(): void
    {
        if ($this->section === 'containers') {
            $this->node->load('containers');

            return;
        }
        if ($this->section !== 'workloads') {
            return;
        }

        // Rows are built in render(). Only the editable form state is kept here.
        $workloads = $this->node->workloads()
            ->where('node_workloads.team_id', $this->node->team_id)
            ->get(['node_workloads.id', 'node_workloads.uuid', 'node_workloads.internal_dns_name']);
        $this->dnsNames = $workloads
            ->mapWithKeys(fn (NodeWorkload $workload): array => [$workload->uuid => $workload->internal_dns_name ?? ''])
            ->all();
        foreach ($workloads as $workload) {
            $this->moveTargets[$workload->uuid] ??= '';
        }
    }

    private function runAction(callable $action, string $message): void
    {
        try {
            $this->authorize('manageSentinel', $this->node);
            $action();
            $this->dispatch('success', $message);
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }
}
