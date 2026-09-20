<?php

namespace App\Actions\Node;

use App\Enums\NodeOperationStatus;
use App\Models\Node;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

class PublishNodeDiscoveryEndpoints
{
    use AsAction;

    private const ENDPOINT_TTL_SECONDS = 300;

    public function handle(Node $node, Carbon $observedAt): void
    {
        $node->ensureCapability('discovery.corrosion.endpoints.reconcile.v1');

        $node->loadMissing('cluster');
        if ($node->cluster === null || $node->cluster->network_status !== 'active' || blank($node->wireguard_ip)) {
            return;
        }

        $updatedAt = $observedAt->getTimestamp();
        $workloadDnsNames = EnsureNodeWorkloadDnsNames::run($node);
        $workloadAddresses = $node->workloads()->pluck('node_workload_nodes.container_ip', 'node_workloads.id');
        $nodeId = Str::slug($node->name);
        if ($nodeId === '') {
            $nodeId = strtolower($node->uuid);
        }
        $nodeEndpoint = [
            'workload_id' => Str::limit($nodeId, 63, ''),
            'namespace' => 'nodes',
            'owner_node_ip' => $node->wireguard_ip,
            'container_ip' => $node->wireguard_ip,
            'state' => 'running',
            'health' => 'healthy',
            'updated_at_unix_seconds' => $updatedAt,
            'expires_at_unix_seconds' => $updatedAt + self::ENDPOINT_TTL_SECONDS,
        ];
        $endpoints = $node->containers()
            ->where('is_managed', true)
            ->with('workload:id,uuid,name')
            ->orderBy('id')
            ->get()
            ->filter(fn ($container): bool => $container->workload !== null)
            ->map(function ($container) use ($node, $updatedAt, $workloadDnsNames, $workloadAddresses): array {
                return [
                    'workload_id' => $workloadDnsNames[$container->workload->id],
                    'namespace' => 'default',
                    'owner_node_ip' => $node->wireguard_ip,
                    'container_ip' => $workloadAddresses[$container->workload->id] ?? $node->wireguard_ip,
                    'state' => $this->discoveryState($container->state),
                    'health' => $this->discoveryHealth($container->health_status),
                    'updated_at_unix_seconds' => $updatedAt,
                    'expires_at_unix_seconds' => $updatedAt + self::ENDPOINT_TTL_SECONDS,
                ];
            })
            ->prepend($nodeEndpoint)
            ->values()
            ->all();
        $request = [
            'owner_node_ip' => $node->wireguard_ip,
            'endpoints' => $endpoints,
        ];
        $operation = CreateOperation::run(
            $node,
            'discovery.corrosion.endpoints.reconcile.v1',
            'discovery-endpoints:'.hash('sha256', json_encode($request, JSON_THROW_ON_ERROR)),
            request: $request,
        );
        if ($operation->status->isFinal()) {
            return;
        }

        try {
            TransitionOperation::run($operation, NodeOperationStatus::DISPATCHED);
            $operation = TransitionOperation::run($operation, NodeOperationStatus::RUNNING);
            $url = config('constants.flux.internal_url');
            $token = config('constants.flux.internal_token');
            if (! is_string($url) || blank($url) || ! is_string($token) || blank($token)) {
                throw new RuntimeException('Flux internal API configuration is incomplete.');
            }
            $response = Http::withToken($token)
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout(120)
                ->post(rtrim($url, '/').'/v1/commands/discovery.corrosion.endpoints.reconcile', [
                    'server_id' => $node->uuid,
                    'command_id' => $operation->uuid,
                    ...$request,
                ]);
            $response->throw();
            $result = $response->json();
            if (! is_array($result)
                || data_get($result, 'command_id') !== $operation->uuid
                || data_get($result, 'owner_node_ip') !== $node->wireguard_ip
                || data_get($result, 'endpoint_count') !== count($endpoints)
                || ! is_numeric(data_get($result, 'observed_at_unix_ms'))) {
                throw new RuntimeException('Flux returned an invalid discovery endpoint result.');
            }
            $operation = TransitionOperation::run($operation, NodeOperationStatus::VERIFYING, result: $result);
            TransitionOperation::run($operation, NodeOperationStatus::SUCCEEDED, result: $result);
        } catch (Throwable $exception) {
            $operation->refresh();
            if (! $operation->status->isFinal()) {
                TransitionOperation::run(
                    $operation,
                    NodeOperationStatus::FAILED,
                    error: mb_substr($exception->getMessage(), 0, 2000),
                );
            }
            throw $exception;
        }
    }

    private function discoveryState(string $state): string
    {
        return in_array($state, ['configured', 'created', 'running', 'paused', 'restarting', 'stopped', 'exited', 'dead', 'removing'], true)
            ? $state
            : 'stopped';
    }

    private function discoveryHealth(?string $health): string
    {
        return in_array($health, ['healthy', 'unhealthy', 'starting', 'unknown'], true)
            ? $health
            : 'unknown';
    }
}
