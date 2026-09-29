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
use App\Actions\Node\PublishNodeDiscoveryEndpoints;
use App\Actions\Node\RepairFluxTrust;
use App\Actions\Node\UpgradeSentinel;
use App\Actions\Node\ValidateNode;
use App\Actions\Sentinel\FetchFluxNodeInformation;
use App\Actions\Sentinel\PingFluxConnection;
use App\Actions\Sentinel\RenewFluxCertificate;
use App\Enums\NodeOperationStatus;
use App\Enums\NodeWorkloadAction;
use App\Jobs\DeployNodeWorkloadJob;
use App\Jobs\ManageNodeWorkloadJob;
use App\Jobs\MoveNodeWorkloadJob;
use App\Jobs\UpgradeNodeSentinelJob;
use App\Models\Node;
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

    /** @var array<string, array{status: string, type: string}> */
    public array $workloadStates = [];

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
        $this->runAction(fn () => ValidateNode::run($this->node), 'Node validation completed.');
        $this->node->refresh();
        $this->loadSectionData();
    }

    public function refreshInformation(): void
    {
        $this->runAction(fn () => FetchFluxNodeInformation::run($this->node), 'Node details refreshed through Flux.');
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

    public function manageWorkload(string $actionValue, string $revisionUuid): void
    {
        try {
            $this->authorize('update', $this->node);
            $action = NodeWorkloadAction::from($actionValue);
            $revision = NodeWorkloadRevision::query()
                ->with('workload')
                ->where('uuid', $revisionUuid)
                ->whereHas('workload', fn ($query) => $query
                    ->where('team_id', $this->node->team_id)
                    ->whereHas('nodes', fn ($nodes) => $nodes->whereKey($this->node->id)))
                ->firstOrFail();
            $operation = CreateLifecycleOperation::run($this->node, $revision, $action, auth()->user());
            ManageNodeWorkloadJob::dispatch($operation->id);
            $this->dispatch('success', str($action->value)->title().' command queued.');
            $this->loadNodeData();
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function refreshWorkloads(): void
    {
        $this->authorize('view', $this->node);
        $this->loadNodeData();
        $this->dispatch('info', 'Workload state refreshed.');
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

            $workload = DB::transaction(function () use ($field, $workloadUuid): NodeWorkload {
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

                $workload->update(['internal_dns_name' => $dnsName]);

                return $workload->load('nodes.cluster');
            });

            foreach ($workload->nodes as $workloadNode) {
                PublishNodeDiscoveryEndpoints::run($workloadNode, now());
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
        $this->runAction(fn () => RenewFluxCertificate::run(null, true), 'Flux TLS certificate renewed.');
    }

    public function refreshFluxConnection(): void
    {
        $this->authorize('view', $this->node);
        $this->loadFluxConnection();
        $this->dispatch('info', 'Flux connection state refreshed.');
    }

    public function render(): View
    {
        return view('livewire.node.show');
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

        $this->node->load([
            'cluster.nodes',
            'workloads' => fn ($query) => $query->with([
                'environment.project',
                'revisions' => fn ($revisions) => $revisions->latest('id')->limit(1),
            ]),
            'operations' => fn ($query) => $query->with('workload')->whereNotNull('node_workload_id')->latest('id')->limit(10),
        ]);
        $this->workloadStates = $this->node->workloads
            ->mapWithKeys(function ($workload): array {
                $state = DetermineWorkloadState::run($this->node, $workload);

                return [$workload->uuid => [
                    'status' => str($state->value)->title()->toString(),
                    'type' => $state->badgeType(),
                ]];
            })
            ->all();
        $this->dnsNames = $this->node->workloads
            ->mapWithKeys(fn ($workload): array => [$workload->uuid => $workload->internal_dns_name ?? ''])
            ->all();
        foreach ($this->node->workloads as $workload) {
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
