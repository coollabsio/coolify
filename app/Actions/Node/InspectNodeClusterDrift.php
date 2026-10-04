<?php

namespace App\Actions\Node;

use App\Enums\NodeOperationStatus;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\NodeOperation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

class InspectNodeClusterDrift
{
    use AsAction;

    private const CAPABILITIES = ['network.wireguard.inspect.v1', 'network.firewall.inspect.v1', 'discovery.corrosion.inspect.v1'];

    /**
     * Inspects every reachable, converged member Node and returns the IDs of the Nodes whose
     * host network drifted from the desired revision. A failed inspection counts as drift.
     *
     * @return list<int>
     */
    public function handle(NodeCluster $cluster): array
    {
        $healthy = $cluster->nodes()
            ->orderBy('id')
            ->get()
            ->filter(fn (Node $node): bool => $cluster->nodeNetworkState($node) === 'converged' && $node->canReceiveNetworkCommands())
            ->values();

        $drifted = [];
        foreach ($healthy as $node) {
            // A Sentinel without inspection support cannot report drift; repairing it would not help.
            if (collect(self::CAPABILITIES)->contains(fn (string $capability): bool => $node->supportsCapability($capability) === false)) {
                continue;
            }
            try {
                if ($this->nodeHasDrift($cluster, $node, $healthy->count() - 1)) {
                    $drifted[] = $node->id;
                }
            } catch (Throwable) {
                $drifted[] = $node->id;
            }
        }

        return $drifted;
    }

    private function nodeHasDrift(NodeCluster $cluster, Node $node, int $expectedAlivePeers): bool
    {
        $wireguard = $this->runOperation($node, 'network.wireguard.inspect.v1', [
            'interface' => $cluster->wireguard_interface,
            'expected_revision' => $cluster->desired_revision,
            'expected_hash' => (string) data_get($node->network_observed_state, 'configuration_hash', ''),
        ]);
        if (data_get($wireguard, 'drifted') !== false
            || data_get($wireguard, 'applied_revision') !== $cluster->desired_revision
            || data_get($wireguard, 'listen_port') !== $cluster->wireguard_port) {
            return true;
        }

        $firewall = $this->runOperation($node, 'network.firewall.inspect.v1', [
            'expected_revision' => $cluster->desired_revision,
            'expected_hash' => (string) data_get($node->metadata, 'firewall_configuration_hash', ''),
        ]);
        if (data_get($firewall, 'drifted') !== false
            || data_get($firewall, 'applied_revision') !== $cluster->desired_revision
            || data_get($firewall, 'table') !== 'coolify_cluster'
            || data_get($firewall, 'ingress_enforced') !== true) {
            return true;
        }

        $corrosion = $this->runOperation($node, 'discovery.corrosion.inspect.v1', []);

        return ! ReconcileNodeClusterNetwork::corrosionConverged($corrosion, $expectedAlivePeers);
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function runOperation(Node $node, string $commandType, array $request): array
    {
        $operation = CreateOperation::run($node, $commandType, 'network-drift:'.Str::uuid(), request: $request);

        try {
            TransitionOperation::run($operation, NodeOperationStatus::DISPATCHED);
            $operation = TransitionOperation::run($operation, NodeOperationStatus::RUNNING);
            $result = $this->dispatch($operation);
            $operation = TransitionOperation::run($operation, NodeOperationStatus::VERIFYING, result: $result);
            TransitionOperation::run($operation, NodeOperationStatus::SUCCEEDED, result: $result);

            return $result;
        } catch (Throwable $exception) {
            $operation->refresh();
            if (! $operation->status->isFinal()) {
                TransitionOperation::run($operation, NodeOperationStatus::FAILED, error: mb_substr($exception->getMessage(), 0, 2000));
            }
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function dispatch(NodeOperation $operation): array
    {
        $url = config('constants.flux.internal_url');
        $token = config('constants.flux.internal_token');
        if (! is_string($url) || blank($url) || ! is_string($token) || blank($token)) {
            throw new RuntimeException('Flux internal API configuration is incomplete.');
        }

        $response = Http::withToken($token)->acceptJson()->connectTimeout(10)->timeout(120)
            ->post(rtrim($url, '/').'/v1/commands/'.Str::beforeLast($operation->command_type, '.v1'), [
                'server_id' => $operation->node->uuid,
                'command_id' => $operation->uuid,
                ...$operation->request,
            ]);
        $response->throw();
        $result = $response->json();
        if (! is_array($result)
            || data_get($result, 'command_id') !== $operation->uuid
            || ! is_numeric(data_get($result, 'observed_at_unix_ms'))) {
            throw new RuntimeException('Flux returned an invalid drift inspection result.');
        }

        return $result;
    }
}
