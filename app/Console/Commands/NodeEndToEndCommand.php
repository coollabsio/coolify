<?php

namespace App\Console\Commands;

use App\Actions\Node\AssignNodeToCluster;
use App\Actions\Node\CreateDeploymentOperation;
use App\Actions\Node\CreateLifecycleOperation;
use App\Actions\Node\PrepareNodeWorkloadRevision;
use App\Actions\Node\ReconcileNodeClusterNetwork;
use App\Actions\Node\RemoveNodeFromCluster;
use App\Actions\Node\SetNodeIngress;
use App\Actions\Node\UpdateNodeWorkloadDomains;
use App\Actions\Sentinel\PingFluxConnection;
use App\Actions\Sentinel\RotateFluxCertificateAuthority;
use App\Enums\NodeOperationStatus;
use App\Enums\NodeWorkloadAction;
use App\Jobs\DeployNodeWorkloadJob;
use App\Jobs\ManageNodeWorkloadJob;
use App\Jobs\ReconcileNodeClusterNetworkJob;
use App\Models\FluxCertificate;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\NodeFirewallRule;
use App\Models\NodeOperation;
use App\Models\NodeWorkload;
use App\Models\Team;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

/**
 * Coolify-side steps of scripts/node-e2e. Every action prints one JSON document,
 * so the host-side script does not need tinker one-liners.
 */
class NodeEndToEndCommand extends Command
{
    public const CLUSTER_NAME = 'Development QEMU mesh';

    /** The CA switch writes this request and waits for scripts/node-e2e to restart the Flux container. */
    public const FLUX_RESTART_DIRECTORY = 'app/node-e2e';

    private const ACTIONS = [
        'status', 'deploy', 'lifecycle', 'operation', 'await-operation', 'firewall-add', 'firewall-remove', 'reconcile',
        'ping', 'ca-start', 'ca-continue', 'ca-cancel', 'ca-status', 'remove-node', 'add-node', 'ingress', 'domains',
    ];

    protected $signature = 'dev:node-e2e
        {action : One of status, deploy, lifecycle, operation, await-operation, firewall-add, firewall-remove, reconcile, ping, ca-start, ca-continue, ca-cancel, ca-status, remove-node, add-node, ingress, domains}
        {argument? : Node (a, b, or a UUID), lifecycle action (start or stop), operation UUID, or the workload domains (comma separated, empty to remove them)}
        {value? : on or off for ingress}
        {--http-port= : Container HTTP port for domains}
        {--pull=missing : Image pull policy for deploy (missing or newer)}
        {--port=65000 : TCP port of the end-to-end firewall rule}
        {--force : Force the next CA rotation step}';

    protected $description = 'Development only: Coolify-side steps and JSON status for scripts/node-e2e';

    public function handle(): int
    {
        if (! isDev()) {
            $this->error('This command may only run in development mode.');

            return self::FAILURE;
        }
        $action = (string) $this->argument('action');
        if (! in_array($action, self::ACTIONS, true)) {
            $this->error('Unknown action. Use one of: '.implode(', ', self::ACTIONS).'.');

            return self::INVALID;
        }

        try {
            $result = match ($action) {
                'status' => $this->status(),
                'deploy' => $this->deploy(),
                'lifecycle' => $this->lifecycle(),
                'operation' => $this->operationSummary(NodeOperation::query()->where('uuid', (string) $this->argument('argument'))->firstOrFail()),
                'await-operation' => $this->awaitOperation(),
                'firewall-add' => $this->addFirewallRule(),
                'firewall-remove' => $this->removeFirewallRule(),
                'reconcile' => $this->queueReconciliation(),
                'ping' => PingFluxConnection::run($this->node()),
                'ca-start' => $this->rotation(fn (RotateFluxCertificateAuthority $rotation) => $rotation->start($this->user())),
                'ca-continue' => $this->rotation(fn (RotateFluxCertificateAuthority $rotation) => $rotation->advance((bool) $this->option('force'), $this->restartFluxThroughHost(...))),
                'ca-cancel' => $this->rotation(fn (RotateFluxCertificateAuthority $rotation) => $rotation->cancel()),
                'ca-status' => RotateFluxCertificateAuthority::make()->status(),
                'remove-node' => $this->removeNode(),
                'add-node' => $this->addNode(),
                'ingress' => $this->setIngress(),
                'domains' => $this->setDomains(),
            };
        } catch (Throwable $exception) {
            $this->line(json_encode(['error' => $exception->getMessage()], JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }

        $this->line(json_encode($result, JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function status(): array
    {
        $cluster = $this->cluster();
        $workload = $this->workload();
        $nodes = Node::query()->whereIn('uuid', ['development-qemu-node-worker-a', 'development-qemu-node-worker-b'])->orderBy('uuid')->get();
        $trust = RotateFluxCertificateAuthority::make()->status();

        return [
            'cluster' => [
                'id' => $cluster->id,
                'network_status' => $cluster->network_status,
                'desired_revision' => $cluster->desired_revision,
                'last_reconciled_at' => $cluster->last_reconciled_at?->toIso8601String(),
                'network_unhealthy_notified_at' => $cluster->network_unhealthy_notified_at,
                'derived_network_status' => $cluster->deriveNetworkStatus(),
            ],
            'nodes' => $nodes->mapWithKeys(fn (Node $node): array => [str($node->uuid)->afterLast('-')->value() => [
                'id' => $node->id,
                'uuid' => $node->uuid,
                'name' => $node->name,
                'ip' => $node->ip,
                'cluster_id' => $node->node_cluster_id,
                'wireguard_ip' => $node->wireguard_ip,
                'connected' => $node->hasRecentFluxHeartbeat(),
                'is_usable' => (bool) $node->is_usable,
                'sentinel_version' => data_get(Cache::get($node->cacheKey()), 'sentinel_version') ?? $node->sentinel_version,
                'network_state' => $node->node_cluster_id === $cluster->id ? $cluster->nodeNetworkState($node) : null,
                'network_status' => $node->network_status,
                'network_error' => $node->network_error,
                'network_attempts' => $node->network_attempts,
                'network_next_attempt_at' => $node->network_next_attempt_at?->toIso8601String(),
                'network_applied_revision' => $node->network_applied_revision,
                'network_pending_leave' => $node->network_pending_leave !== null,
                'corrosion_status' => $node->corrosion_status,
                'unreachable_since' => $node->unreachable_since,
                'unreachable_notified_at' => $node->unreachable_notified_at,
                'flux_trust_bundle_version' => $node->flux_trust_bundle_version,
                'flux_trust_bundle_error' => $node->flux_trust_bundle_error,
                'is_ingress' => (bool) $node->is_ingress,
                'ingress_state' => $node->node_cluster_id === $cluster->id ? $cluster->nodeIngressState($node) : null,
            ]])->all(),
            'workload' => $workload === null ? null : [
                'uuid' => $workload->uuid,
                'name' => $workload->name,
                'container_name' => 'coolify-'.$workload->uuid.'-main',
                'desired_state' => $workload->desired_state?->value,
                'node_uuids' => $workload->nodes()->pluck('nodes.uuid')->all(),
                'container_ips' => $workload->nodes()->get()->pluck('pivot.container_ip')->filter()->values()->all(),
                'domains' => $workload->domains ?? [],
                'http_port' => $workload->http_port,
                'active_operations' => NodeOperation::query()
                    ->where('node_workload_id', $workload->id)
                    ->whereIn('status', [NodeOperationStatus::QUEUED, NodeOperationStatus::DISPATCHED, NodeOperationStatus::RUNNING, NodeOperationStatus::VERIFYING, NodeOperationStatus::UNCERTAIN])
                    ->orderBy('id')
                    ->get()
                    ->map(fn (NodeOperation $operation): array => $this->operationSummary($operation))
                    ->all(),
            ],
            'notification_channels' => collect(['server_unreachable', 'server_reachable'])->mapWithKeys(fn (string $event): array => [
                $event => array_map(fn (string $channel): string => class_basename($channel), $cluster->team->getEnabledChannels($event)),
            ])->all(),
            'trust' => [
                'bundle_version' => $trust['bundle_version'],
                'rotation' => $trust['rotation'],
                'next_step' => $trust['next_step'],
                'blocking' => $trust['blocking'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function deploy(): array
    {
        $workload = $this->workload() ?? throw new RuntimeException('The cluster has no workload.');
        $node = $workload->nodes()->firstOrFail();
        $pullPolicy = (string) $this->option('pull');
        $deployment = CreateDeploymentOperation::run($node, PrepareNodeWorkloadRevision::run($workload), $this->user(), $pullPolicy);
        if ($deployment['created']) {
            DeployNodeWorkloadJob::dispatch($deployment['operation']->id);
        }

        return [...$this->operationSummary($deployment['operation']), 'created' => $deployment['created']];
    }

    /** Runs a start or stop through the same job as the UI, synchronously. */
    private function lifecycle(): array
    {
        $action = NodeWorkloadAction::from((string) $this->argument('argument'));
        if (! in_array($action, [NodeWorkloadAction::START, NodeWorkloadAction::STOP], true)) {
            throw new RuntimeException('Use start or stop.');
        }
        $workload = $this->workload() ?? throw new RuntimeException('The cluster has no workload.');
        $node = $workload->nodes()->firstOrFail();
        $operation = CreateLifecycleOperation::run($node, $workload->revisions()->latest('id')->firstOrFail(), $action, $this->user());
        ManageNodeWorkloadJob::dispatchSync($operation->id);

        return $this->operationSummary($operation->refresh());
    }

    /** Polls an operation every 100 ms until it leaves `queued` (at most 60 s), to time a fault injection. */
    private function awaitOperation(): array
    {
        $operation = NodeOperation::query()->where('uuid', (string) $this->argument('argument'))->firstOrFail();
        for ($attempt = 0; $attempt < 600 && $operation->refresh()->status === NodeOperationStatus::QUEUED; $attempt++) {
            Sleep::for(100)->milliseconds();
        }

        return $this->operationSummary($operation);
    }

    /** Adds a harmless rule (Node A to the workload) the way the cluster page does. */
    private function addFirewallRule(): array
    {
        $cluster = $this->cluster();
        $workload = $this->workload() ?? throw new RuntimeException('The cluster has no workload.');
        $source = $this->node('a');
        $rule = DB::transaction(function () use ($cluster, $workload, $source): NodeFirewallRule {
            $rule = NodeFirewallRule::query()->firstOrCreate([
                'node_cluster_id' => $cluster->id,
                'source_workload_id' => null,
                'source_node_id' => $source->id,
                'destination_workload_id' => $workload->id,
                'protocol' => 'tcp',
                'port' => (int) $this->option('port'),
            ]);
            if ($rule->wasRecentlyCreated) {
                $cluster->increment('desired_revision');
            }

            return $rule;
        });
        $this->queueReconciliation();

        return ['rule' => $rule->uuid, 'desired_revision' => $cluster->refresh()->desired_revision];
    }

    private function removeFirewallRule(): array
    {
        $cluster = $this->cluster();
        $removed = DB::transaction(function () use ($cluster): int {
            $removed = NodeFirewallRule::query()
                ->where('node_cluster_id', $cluster->id)
                ->where('protocol', 'tcp')
                ->where('port', (int) $this->option('port'))
                ->delete();
            if ($removed > 0) {
                $cluster->increment('desired_revision');
            }

            return $removed;
        });
        if ($removed > 0) {
            $this->queueReconciliation();
        }

        return ['removed' => $removed, 'desired_revision' => $cluster->refresh()->desired_revision];
    }

    /** Queues a full network reconciliation the way the cluster page does. */
    private function queueReconciliation(): array
    {
        $cluster = $this->cluster();
        $cluster->update(['network_status' => 'reconciling']);
        ReconcileNodeClusterNetworkJob::dispatch($cluster->id, $this->user()->id);

        return ['queued' => true, 'desired_revision' => $cluster->desired_revision];
    }

    private function removeNode(): array
    {
        $node = RemoveNodeFromCluster::run($this->cluster(), $this->node(), $this->user());

        return ['node' => $node->uuid, 'pending_leave' => $node->network_pending_leave !== null];
    }

    /** Assigns the Node again and reconciles the whole cluster synchronously. */
    private function addNode(): array
    {
        $cluster = $this->cluster();
        AssignNodeToCluster::run($cluster, $this->node());
        $operations = ReconcileNodeClusterNetwork::run($cluster->refresh(), $this->user());

        return ['operations' => count($operations), 'network_status' => $cluster->refresh()->network_status];
    }

    /** Turns ingress on or off for a Node the way the cluster Nodes page does. */
    private function setIngress(): array
    {
        $enabled = match ((string) $this->argument('value')) {
            'on' => true,
            'off' => false,
            default => throw new RuntimeException('Use on or off.'),
        };
        $node = SetNodeIngress::run($this->cluster(), $this->node(), $enabled, $this->user());

        return ['node' => $node->uuid, 'is_ingress' => $node->is_ingress, 'desired_revision' => $this->cluster()->desired_revision];
    }

    /** Saves the domains and HTTP port of the end-to-end workload the way the application page does. */
    private function setDomains(): array
    {
        $workload = $this->workload() ?? throw new RuntimeException('The cluster has no workload.');
        $workload = UpdateNodeWorkloadDomains::run($workload, (string) $this->argument('argument'), $this->option('http-port'), $this->user());

        return ['domains' => $workload->domains ?? [], 'http_port' => $workload->http_port, 'desired_revision' => $this->cluster()->desired_revision];
    }

    /** @param \Closure(RotateFluxCertificateAuthority): mixed $step */
    private function rotation(\Closure $step): array
    {
        $rotation = RotateFluxCertificateAuthority::make();
        $step($rotation);

        return $rotation->status();
    }

    /**
     * The CA switch restarts Flux with the new leaf. The development Flux runs in a host
     * container, so the host script restarts it on request; this side then checks that Flux
     * serves the expected certificate.
     */
    private function restartFluxThroughHost(FluxCertificate $certificate): void
    {
        $directory = storage_path(self::FLUX_RESTART_DIRECTORY);
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        $request = $directory.'/flux-restart.request';
        $done = $directory.'/flux-restart.done';
        @unlink($done);
        file_put_contents($request, $certificate->fingerprint);
        for ($attempt = 0; ! file_exists($done); $attempt++) {
            if ($attempt >= 120) {
                @unlink($request);
                throw new RuntimeException('scripts/node-e2e did not restart Flux within 120 seconds.');
            }
            Sleep::for(1)->second();
        }
        @unlink($done);

        $host = (string) config('constants.flux.tls_verify_host', 'coolify-flux');
        retry(15, function () use ($host, $certificate): void {
            $context = stream_context_create(['ssl' => [
                'capture_peer_cert' => true,
                'verify_peer' => false,
                'verify_peer_name' => false,
                'SNI_server_name' => $certificate->identities[0] ?? $host,
            ]]);
            $client = @stream_socket_client("ssl://{$host}:7443", $errorCode, $errorMessage, 5, STREAM_CLIENT_CONNECT, $context);
            if ($client === false) {
                throw new RuntimeException("Flux TLS is not reachable: {$errorMessage}");
            }
            $peer = stream_context_get_params($client)['options']['ssl']['peer_certificate'] ?? null;
            fclose($client);
            if ($peer === null || openssl_x509_fingerprint($peer, 'sha256') !== $certificate->fingerprint) {
                throw new RuntimeException('Flux did not serve the expected TLS certificate.');
            }
        }, 1000);
    }

    /** @return array<string, mixed> */
    private function operationSummary(NodeOperation $operation): array
    {
        return [
            'uuid' => $operation->uuid,
            'command_type' => $operation->command_type,
            'status' => $operation->status->value,
            'error' => $operation->error,
            'updated_at' => $operation->updated_at?->toIso8601String(),
        ];
    }

    private function cluster(): NodeCluster
    {
        return NodeCluster::query()->where('team_id', 0)->where('name', self::CLUSTER_NAME)->firstOrFail();
    }

    private function workload(): ?NodeWorkload
    {
        return NodeWorkload::query()
            ->where('team_id', 0)
            ->whereHas('nodes', fn ($nodes) => $nodes->where('node_cluster_id', $this->cluster()->id))
            ->orderBy('id')
            ->first();
    }

    private function node(?string $key = null): Node
    {
        $key ??= (string) $this->argument('argument');
        $uuid = in_array($key, ['a', 'b'], true) ? "development-qemu-node-worker-{$key}" : $key;

        return Node::query()->where('team_id', 0)->where('uuid', $uuid)->firstOrFail();
    }

    private function user(): User
    {
        return Team::query()->findOrFail(0)->members()->wherePivot('role', 'owner')->orderBy('users.id')->firstOrFail();
    }
}
