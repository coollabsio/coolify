<?php

namespace App\Actions\Node;

use App\Enums\NodeOperationStatus;
use App\Models\Node;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

/**
 * Tears down the cluster network on one Node with `network.cluster.leave.v1`.
 */
class LeaveNodeClusterNetwork
{
    use AsAction;

    /** @param array{interface: string, owner_node_ip: ?string, workload_cidrs: list<string>} $request */
    public function handle(Node $node, array $request): void
    {
        $node->ensureCapability('network.cluster.leave.v1');
        $operation = CreateOperation::run($node, 'network.cluster.leave.v1', 'node-removal:'.Str::uuid(), request: $request);

        try {
            TransitionOperation::run($operation, NodeOperationStatus::DISPATCHED);
            $operation = TransitionOperation::run($operation, NodeOperationStatus::RUNNING);
            $url = config('constants.flux.internal_url');
            $token = config('constants.flux.internal_token');
            if (! is_string($url) || blank($url) || ! is_string($token) || blank($token)) {
                throw new RuntimeException('Flux internal API configuration is incomplete.');
            }
            $response = Http::withToken($token)->acceptJson()->connectTimeout(10)->timeout(180)
                ->post(rtrim($url, '/').'/v1/commands/network.cluster.leave', [
                    'server_id' => $node->uuid,
                    'command_id' => $operation->uuid,
                    ...$request,
                ]);
            $response->throw();
            $result = $response->json();
            $valid = is_array($result)
                && data_get($result, 'command_id') === $operation->uuid
                && is_numeric(data_get($result, 'observed_at_unix_ms'))
                && data_get($result, 'wireguard_removed') === true
                && data_get($result, 'firewall_removed') === true
                && data_get($result, 'discovery_removed') === true
                && data_get($result, 'resolver_reverted') === true;
            if (! $valid) {
                throw new RuntimeException('Flux returned an invalid Node cleanup result.');
            }
            $operation = TransitionOperation::run($operation, NodeOperationStatus::VERIFYING, result: $result);
            TransitionOperation::run($operation, NodeOperationStatus::SUCCEEDED, result: $result);
        } catch (Throwable $exception) {
            $operation->refresh();
            if (! $operation->status->isFinal()) {
                TransitionOperation::run($operation, NodeOperationStatus::FAILED, error: mb_substr($exception->getMessage(), 0, 2000));
            }
            throw $exception;
        }
    }

    /**
     * Sends a leave that was deferred because the Node was offline when it left its cluster.
     * Returns false when the Node still has a pending leave.
     */
    public static function completePending(Node $node): bool
    {
        $node->refresh();
        if (! is_array($node->network_pending_leave)) {
            return true;
        }
        if (! $node->canReceiveNetworkCommands()) {
            return false;
        }

        $lock = Cache::lock("node-network-leave:{$node->id}", 300);
        if (! $lock->get()) {
            return false;
        }

        try {
            $node->refresh();
            if (! is_array($node->network_pending_leave)) {
                return true;
            }
            static::run($node, $node->network_pending_leave);
            $node->update(['network_pending_leave' => null]);

            return true;
        } finally {
            $lock->release();
        }
    }
}
