<?php

namespace App\Http\Controllers\Api;

use App\Actions\Node\QueueNodeClusterNetworkRevision;
use App\Actions\Node\QueueNodeNetworkConvergence;
use App\Actions\Node\ReconcileNodeClusterNetwork;
use App\Actions\Node\RecordNodeWorkloadStatus;
use App\Actions\Node\SyncNodeWorkloadSet;
use App\Actions\Sentinel\ResolveFluxPublicUrl;
use App\Actions\Sentinel\ResolveFluxTrustBundle;
use App\Actions\Sentinel\RotateFluxCertificateAuthority;
use App\Http\Controllers\Controller;
use App\Jobs\DistributeFluxTrustBundleJob;
use App\Jobs\RefreshNodeContainersJob;
use App\Jobs\SyncNodeWorkloadSetJob;
use App\Models\FluxCaRotation;
use App\Models\Node;
use App\Models\NodeCluster;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class FluxConnectionEventController extends Controller
{
    public function __invoke(Request $request, ResolveFluxPublicUrl $resolveFluxPublicUrl): Response
    {
        abort_unless(nodesInUse(), 404);

        $expectedToken = config('constants.flux.internal_token');
        $providedToken = $request->bearerToken();
        if (! is_string($expectedToken) || $expectedToken === '' || ! is_string($providedToken) || ! hash_equals($expectedToken, $providedToken)) {
            abort(401);
        }

        $validator = Validator::make($request->all(), [
            'event' => ['required', 'in:connected,heartbeat,disconnected,runtime_changed,workload_status'],
            'server_id' => ['required', 'string', 'max:255'],
            'connection_id' => ['required', 'uuid'],
            'sentinel_version' => ['required_if:event,connected', 'nullable', 'string', 'max:100'],
            'protocol_version' => ['required_if:event,connected', 'nullable', 'integer', 'min:1'],
            'trust_bundle_version' => ['required_if:event,connected', 'nullable', 'integer', 'min:1'],
            'transport' => ['required_if:event,connected', 'nullable', 'in:tls,plaintext'],
            'capabilities' => ['nullable', 'array', 'max:128'],
            'capabilities.*' => ['required', 'string', 'max:100', 'distinct'],
            'observed_at_unix_ms' => ['nullable', 'integer', 'min:0'],
            'event_id' => ['required_if:event,runtime_changed', 'required_if:event,workload_status', 'nullable', 'string', 'max:255'],
            'workload_generation' => ['nullable', 'integer', 'min:0'],
            'workload_set_hash' => ['nullable', 'string', 'max:64'],
            'workload' => ['required_if:event,workload_status', 'nullable', 'array'],
            'workload.workload_id' => ['required_with:workload', 'string', 'max:64'],
            'workload.revision_id' => ['nullable', 'string', 'max:64'],
            'workload.operation_id' => ['nullable', 'string', 'max:64'],
            'workload.status' => ['required_with:workload', 'string', 'in:progressing,healthy,failed,stopped,removed'],
            'workload.reason' => ['nullable', 'string', 'max:4000'],
            'workload.generation' => ['nullable', 'integer', 'min:0'],
            'workload.container_name' => ['nullable', 'string', 'max:255'],
            'workload.runtime_id' => ['nullable', 'string', 'max:128'],
            'workload.autonomous' => ['nullable', 'boolean'],
        ]);
        if ($validator->fails()) {
            abort(422, $validator->errors()->first());
        }
        $data = $validator->validated();
        $node = Node::query()->where('uuid', $data['server_id'])->firstOrFail();
        $key = $node->cacheKey();
        $current = Cache::get($key, []);

        if ($data['event'] === 'runtime_changed') {
            if (data_get($current, 'status') === 'connected'
                && data_get($current, 'connection_id') === $data['connection_id']) {
                RefreshNodeContainersJob::dispatch($node->id);
            }

            return response()->noContent();
        }

        if ($data['event'] === 'workload_status') {
            // Flux acknowledges the event to Sentinel only after this response, so a failure here
            // makes Sentinel send it again.
            RecordNodeWorkloadStatus::run($node, [
                ...$data['workload'],
                'event_id' => $data['event_id'],
                'observed_at_unix_ms' => $data['observed_at_unix_ms'] ?? null,
            ]);

            return response()->noContent();
        }

        if ($data['event'] === 'disconnected') {
            if (data_get($current, 'connection_id') === $data['connection_id']) {
                Cache::put($key, [
                    ...$current,
                    'status' => 'reconnecting',
                ], now()->addSeconds(15));
                $node->update(['is_reachable' => false]);
            }

            return response()->noContent();
        }

        if ($data['event'] === 'heartbeat' && data_get($current, 'connection_id') !== $data['connection_id']) {
            return response()->noContent();
        }

        $fluxUrl = $resolveFluxPublicUrl->resolve();
        $observedAt = now()->toIso8601String();
        Cache::put($key, array_filter([
            'status' => 'connected',
            'connection_id' => $data['connection_id'],
            'sentinel_version' => $data['sentinel_version'] ?? data_get($current, 'sentinel_version'),
            'protocol_version' => $data['protocol_version'] ?? data_get($current, 'protocol_version'),
            'connected_at' => $data['event'] === 'connected' && data_get($current, 'status') !== 'reconnecting'
                ? now()->toIso8601String()
                : data_get($current, 'connected_at'),
            // connected_at survives a quick reconnect; this marks every new Sentinel connection.
            'last_connected_at' => $data['event'] === 'connected' ? now()->toIso8601String() : data_get($current, 'last_connected_at'),
            'last_heartbeat_at' => $observedAt,
            'trust_bundle_version' => $data['trust_bundle_version'] ?? data_get($current, 'trust_bundle_version'),
            'transport' => $data['transport'] ?? data_get($current, 'transport'),
            'endpoint' => $fluxUrl,
            'capabilities' => $data['capabilities'] ?? data_get($current, 'capabilities'),
            // The desired set of Sentinel (decision 0026), from `Hello` and each heartbeat.
            'workload_generation' => $data['workload_generation'] ?? null,
            'workload_set_hash' => $data['workload_set_hash'] ?? null,
        ], fn ($value) => $value !== null), now()->addMinutes(5));
        $reconnected = $data['event'] === 'connected' || ! $node->is_reachable;
        if (! $node->is_reachable) {
            $node->update(['is_reachable' => true]);
        }
        if ($data['event'] === 'connected' && filled($data['sentinel_version'] ?? null) && $node->sentinel_version !== $data['sentinel_version']) {
            $node->update(['sentinel_version' => $data['sentinel_version']]);
        }
        if (isset($data['capabilities']) && $node->sentinel_capabilities !== $data['capabilities']) {
            $hadRoutesV2 = in_array(NodeCluster::INGRESS_ROUTES_CAPABILITY, $node->sentinel_capabilities ?? [], true);
            $hadLocalPorts = in_array(ReconcileNodeClusterNetwork::PUBLISHED_PORTS_LOCAL_CAPABILITY, $node->sentinel_capabilities ?? [], true);
            $node->update(['sentinel_capabilities' => $data['capabilities']]);
            $localPortsChanged = $hadLocalPorts !== in_array(ReconcileNodeClusterNetwork::PUBLISHED_PORTS_LOCAL_CAPABILITY, $data['capabilities'], true);
            // The route table shape depends on this capability on every Node, so a converged cluster must resend it.
            // The published ports of the cluster change between the rules of decisions 0016 and 0037, and the
            // firewall of a new Sentinel must accept the connections that its local rules translate.
            if ($node->cluster !== null && ($localPortsChanged || $hadRoutesV2 !== in_array(NodeCluster::INGRESS_ROUTES_CAPABILITY, $data['capabilities'], true))) {
                rescue(fn () => QueueNodeClusterNetworkRevision::run($node->cluster));
            }
            if ($node->cluster !== null && $localPortsChanged) {
                // The desired set entries get or lose their local published ports.
                rescue(fn () => SyncNodeWorkloadSet::queueForCluster($node->cluster, 15));
            }
        }
        if ($data['event'] === 'connected' && isset($data['trust_bundle_version'])) {
            $this->recordTrustBundle($node, (int) $data['trust_bundle_version']);
        }
        if ($reconnected) {
            rescue(fn () => QueueNodeNetworkConvergence::run($node->refresh()));
        }
        if (array_key_exists('workload_generation', $data) && $data['workload_generation'] !== null) {
            $matches = (int) $data['workload_generation'] === (int) $node->workload_set_generation
                && (string) ($data['workload_set_hash'] ?? '') === (string) $node->workload_set_hash;
            if ($data['event'] === 'connected' || ! $matches) {
                // A later Hello or heartbeat carries the state after an earlier change: check again
                // after a short delay instead of sending the full set for a change in flight.
                rescue(fn () => SyncNodeWorkloadSetJob::dispatch($node->id, $data['event'] === 'connected')
                    ->delay($data['event'] === 'connected' ? 0 : 15));
            }
        }

        return response()->noContent();
    }

    /**
     * Record the trust bundle Sentinel reported and deliver a newer one, so a
     * Node that was offline during a CA rotation catches up when it reconnects.
     */
    private function recordTrustBundle(Node $node, int $version): void
    {
        if ($node->flux_trust_bundle_version !== $version) {
            $node->forceFill([
                'flux_trust_bundle_version' => $version,
                'flux_trust_bundle_acknowledged_at' => now(),
            ])->saveQuietly();
        }
        if (! FluxCaRotation::query()->exists()) {
            return;
        }
        rescue(fn () => RotateFluxCertificateAuthority::make()->finishCancelWhenAcknowledged(), report: false);
        rescue(function () use ($node, $version): void {
            if ($version < ResolveFluxTrustBundle::run()['version']) {
                DistributeFluxTrustBundleJob::dispatch($node->id);
            }
        });
    }
}
