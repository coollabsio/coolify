<?php

namespace App\Livewire\Node;

use App\Actions\Node\FetchContainers;
use App\Actions\Node\InstallSentinel;
use App\Actions\Node\RepairFluxTrust;
use App\Actions\Node\ValidateNode;
use App\Actions\Sentinel\FetchFluxNodeInformation;
use App\Actions\Sentinel\PingFluxConnection;
use App\Actions\Sentinel\RenewFluxCertificate;
use App\Models\Node;
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
        $this->node->load('containers');
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
            $this->node->load('containers');
            $label = $count === 1 ? 'container' : 'containers';
            $this->dispatch('success', "Container inventory refreshed. {$count} {$label} found.");
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

    private function loadFluxConnection(): void
    {
        $this->fluxConnection = Cache::get($this->node->cacheKey());
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
