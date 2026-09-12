<?php

namespace App\Livewire\Node;

use App\Actions\Node\CreateOperation;
use App\Actions\Node\FetchContainers;
use App\Actions\Node\InstallSentinel;
use App\Actions\Node\RepairFluxTrust;
use App\Actions\Node\ValidateNode;
use App\Actions\Sentinel\FetchFluxNodeInformation;
use App\Actions\Sentinel\PingFluxConnection;
use App\Actions\Sentinel\RenewFluxCertificate;
use App\Enums\NodeOperationStatus;
use App\Jobs\DeployNodeWorkloadJob;
use App\Models\Node;
use App\Models\NodeOperation;
use App\Models\NodeWorkloadRevision;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Livewire\Component;

class Show extends Component
{
    use AuthorizesRequests;

    public Node $node;

    /** @var array<string, mixed>|null */
    public ?array $fluxConnection = null;

    public function mount(string $node_uuid): void
    {
        abort_unless(isDev() && config('constants.sentinel.host_enabled', false), 404);
        $this->node = Node::query()
            ->where('uuid', $node_uuid)
            ->whereIn('team_id', auth()->user()->teams()->select('teams.id'))
            ->firstOrFail();
        $this->authorize('view', $this->node);
        $this->loadFluxConnection();
        $this->loadNodeData();
    }

    public function installSentinel(): void
    {
        $this->runAction(fn () => InstallSentinel::run($this->node), 'Host Sentinel installed and started.');
    }

    public function restartSentinel(): void
    {
        $this->runAction(fn () => $this->node->restartSentinel(), 'Host Sentinel restarted.');
    }

    public function validateNode(): void
    {
        $this->runAction(fn () => ValidateNode::run($this->node), 'Node validation completed.');
        $this->node->refresh();
    }

    public function refreshInformation(): void
    {
        $this->runAction(fn () => FetchFluxNodeInformation::run($this->node), 'Node details refreshed through Flux.');
        $this->node->refresh();
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
            $operation = CreateOperation::run(
                $this->node,
                'workload.deploy.v1',
                "deploy:{$this->node->uuid}:{$revision->uuid}",
                $revision->workload,
                $revision,
                ['revision_uuid' => $revision->uuid, 'configuration_hash' => $revision->configuration_hash],
                auth()->user(),
            );
            if ($operation->wasRecentlyCreated) {
                DeployNodeWorkloadJob::dispatch($operation->id);
                $this->dispatch('success', 'Workload deployment queued.');
            } else {
                $this->dispatch('info', 'This workload revision already has a deployment operation.');
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
                ->where('command_type', 'workload.deploy.v1')
                ->where('status', NodeOperationStatus::UNCERTAIN)
                ->firstOrFail();
            DeployNodeWorkloadJob::dispatch($operation->id);
            $this->dispatch('success', 'Deployment recovery queued.');
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

    private function loadFluxConnection(): void
    {
        $this->fluxConnection = Cache::get($this->node->cacheKey());
    }

    private function loadNodeData(): void
    {
        $this->node->load([
            'containers',
            'workloads' => fn ($query) => $query->with(['revisions' => fn ($revisions) => $revisions->latest('id')->limit(1)]),
            'operations' => fn ($query) => $query->with('workload')->latest('id')->limit(20),
        ]);
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
