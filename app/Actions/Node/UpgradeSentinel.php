<?php

namespace App\Actions\Node;

use App\Actions\Sentinel\FetchNodeLogs;
use App\Enums\NodeOperationStatus;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\NodeOperation;
use App\Models\User;
use Carbon\CarbonInterval;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

/**
 * Upgrades host Sentinel over SSH as a coordinated Node operation. After the install, Sentinel must
 * reconnect to Flux with the expected version and report its capabilities, otherwise the previous
 * binary is restored over SSH.
 */
class UpgradeSentinel
{
    use AsAction;

    public const COMMAND_TYPE = 'sentinel.upgrade.v1';

    public const HEALTH_CHECK_INTERVAL_SECONDS = 2;

    /** Upper bound of the reconnect wait. UpgradeNodeSentinelJob::$timeout and the stale-operation limit assume it. */
    public const MAX_RECONNECT_TIMEOUT_SECONDS = 300;

    private const ACTIVE_STATUSES = [
        NodeOperationStatus::QUEUED,
        NodeOperationStatus::DISPATCHED,
        NodeOperationStatus::RUNNING,
        NodeOperationStatus::VERIFYING,
        NodeOperationStatus::UNCERTAIN,
    ];

    public static function clusterUpgradeCacheKey(int $clusterId): string
    {
        return "sentinel-host:upgrade-cluster:{$clusterId}";
    }

    public static function reconnectTimeoutSeconds(): int
    {
        return max(10, min(self::MAX_RECONNECT_TIMEOUT_SECONDS, (int) config('constants.sentinel.upgrade_reconnect_timeout_seconds')));
    }

    /**
     * Usable cluster Nodes that run an older Sentinel than the release, in upgrade order.
     *
     * @param  array{version: string}|null  $release
     * @return Collection<int, Node>
     */
    public static function clusterNodesToUpgrade(NodeCluster $cluster, ?array $release): Collection
    {
        if ($release === null) {
            return collect();
        }

        return Node::query()
            ->where('node_cluster_id', $cluster->id)
            ->where('team_id', $cluster->team_id)
            ->where('is_usable', true)
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->filter(fn (Node $node): bool => $node->needsSentinelUpgrade($release))
            ->values()
            ->toBase();
    }

    /**
     * The run state shown on the cluster page. Each Node links to its upgrade operation once it starts.
     *
     * @param  Collection<int, Node>  $nodes
     * @return array{status: string, version: ?string, nodes: list<array{uuid: string, name: string, previous_version: ?string, operation_id: ?int}>}
     */
    public static function newClusterRun(string $status, ?string $version, Collection $nodes): array
    {
        return [
            'status' => $status,
            'version' => $version,
            'nodes' => $nodes->map(fn (Node $node): array => [
                'uuid' => $node->uuid,
                'name' => $node->name,
                'previous_version' => $node->runningSentinelVersion(),
                'operation_id' => null,
            ])->values()->all(),
        ];
    }

    /**
     * Creates the upgrade operation. Refuses without creating an operation when the Node has another
     * active operation, no valid release exists, or Sentinel is already current.
     */
    public function start(Node $node, ?User $requestedBy = null): NodeOperation
    {
        if (! self::isEnabled()) {
            throw new RuntimeException('Sentinel upgrades are not available on this instance.');
        }
        $release = FetchLatestSentinelRelease::run();
        if ($release === null) {
            throw new RuntimeException('No valid Sentinel release is available. Try again later.');
        }
        if (! $node->needsSentinelUpgrade($release)) {
            throw new RuntimeException('Sentinel on this server is already up to date.');
        }

        return DB::transaction(function () use ($node, $release, $requestedBy): NodeOperation {
            $node = Node::query()->lockForUpdate()->findOrFail($node->id);
            if ($node->operations()->whereIn('status', self::ACTIVE_STATUSES)->exists()) {
                throw new RuntimeException('Another operation is active on this server. Wait for it to finish, then upgrade Sentinel.');
            }

            return CreateOperation::run(
                $node,
                self::COMMAND_TYPE,
                'sentinel-upgrade:'.Str::uuid(),
                request: [
                    'version' => $release['version'],
                    'digest' => $release['digest'],
                    'image' => $release['image'],
                    'previous_version' => $node->runningSentinelVersion(),
                ],
                requestedBy: $requestedBy,
            );
        });
    }

    /** Runs a queued upgrade operation to a final state. */
    public function handle(NodeOperation $operation): NodeOperation
    {
        if ($operation->command_type !== self::COMMAND_TYPE || $operation->status->isFinal()) {
            return $operation;
        }
        if (ClaimOperation::run($operation, NodeOperationStatus::DISPATCHED) === null) {
            return $operation->refresh();
        }
        $operation = TransitionOperation::run($operation, NodeOperationStatus::RUNNING);
        $node = $operation->node;
        $version = (string) data_get($operation->request, 'version');
        $result = [
            'version' => $version,
            'previous_version' => data_get($operation->request, 'previous_version'),
            'image' => data_get($operation->request, 'image'),
        ];

        try {
            if (! self::isEnabled()) {
                throw new RuntimeException('Sentinel upgrades are not available on this instance.');
            }
            $startedAt = now()->startOfSecond();
            try {
                InstallSentinel::run($node, (string) data_get($operation->request, 'image'));
            } catch (Throwable $exception) {
                throw new RuntimeException("Sentinel {$version} could not be installed. The previous version is still installed. ".$exception->getMessage(), previous: $exception);
            }

            $operation = TransitionOperation::run($operation, NodeOperationStatus::VERIFYING, result: $result);
            if (! $this->waitForReconnect($node, $version, $startedAt)) {
                $within = CarbonInterval::seconds(self::reconnectTimeoutSeconds())->cascade()->forHumans();
                try {
                    RollbackSentinel::run($node);
                    $result['rolled_back'] = true;
                    $error = "Sentinel {$version} did not reconnect with its capabilities within {$within}. The previous version was restored.";
                } catch (Throwable $exception) {
                    $result['rolled_back'] = false;
                    $error = "Sentinel {$version} did not reconnect with its capabilities within {$within}. Restoring the previous version failed: ".$exception->getMessage();
                }

                return $this->fail($operation, $error, $result);
            }

            $node->update(['sentinel_version' => $version]);

            return TransitionOperation::run($operation, NodeOperationStatus::SUCCEEDED, result: $result);
        } catch (Throwable $exception) {
            return $this->fail($operation, $exception->getMessage(), $result);
        }
    }

    /**
     * Upgrades the cluster's Nodes one at a time and stops at the first failure. A Node starts only after
     * the previous one reconnected with its capabilities, so at most one Node runs without Sentinel.
     *
     * @return array{status: string, version: ?string, nodes: list<array{uuid: string, name: string, previous_version: ?string, operation_id: ?int}>, failed_node_name?: string, error?: string, finished_at?: string}
     */
    public function upgradeCluster(NodeCluster $cluster, User $user): array
    {
        $key = self::clusterUpgradeCacheKey($cluster->id);
        $release = FetchLatestSentinelRelease::run();
        $nodes = self::clusterNodesToUpgrade($cluster, $release);
        $run = self::newClusterRun('running', $release['version'] ?? null, $nodes);
        Cache::put($key, $run, now()->addDay());

        if ($release === null) {
            $run = [...$run, 'status' => 'failed', 'error' => 'No valid Sentinel release is available. Try again later.'];
        } elseif (! $user->can('update', $cluster)) {
            $run = [...$run, 'status' => 'failed', 'error' => 'You are not allowed to upgrade Sentinel in this cluster.'];
        }

        foreach ($run['status'] === 'running' ? $nodes : [] as $index => $node) {
            try {
                if (! $user->can('manageSentinel', $node)) {
                    throw new RuntimeException('You are not allowed to upgrade Sentinel on this server.');
                }
                if (! $node->hasRecentFluxHeartbeat()) {
                    throw new RuntimeException('Sentinel on this server is not connected, so it was not upgraded. Fix the connection, then upgrade the cluster again.');
                }
                $operation = $this->start($node, $user);
                $run['nodes'][$index]['operation_id'] = $operation->id;
                Cache::put($key, $run, now()->addDay());

                $operation = $this->handle($operation);
                if ($operation->status !== NodeOperationStatus::SUCCEEDED) {
                    throw new RuntimeException($operation->error ?? 'The Sentinel upgrade did not complete.');
                }
            } catch (Throwable $exception) {
                $run = [
                    ...$run,
                    'status' => 'failed',
                    'failed_node_name' => $node->name,
                    'error' => mb_substr(FetchNodeLogs::redact($exception->getMessage()), 0, 2000),
                ];
                break;
            }
        }

        if ($run['status'] === 'running') {
            $run['status'] = 'succeeded';
        }
        $run['finished_at'] = now()->toIso8601String();
        Cache::put($key, $run, now()->addDay());

        return $run;
    }

    public static function isEnabled(): bool
    {
        return nodesEnabled();
    }

    private function waitForReconnect(Node $node, string $version, Carbon $startedAt): bool
    {
        $attempts = intdiv(self::reconnectTimeoutSeconds(), self::HEALTH_CHECK_INTERVAL_SECONDS);
        for ($attempt = 0; $attempt <= $attempts; $attempt++) {
            if ($this->reconnectedWithVersion($node, $version, $startedAt)) {
                return true;
            }
            if ($attempt < $attempts) {
                Sleep::for(self::HEALTH_CHECK_INTERVAL_SECONDS)->seconds();
            }
        }

        return false;
    }

    private function reconnectedWithVersion(Node $node, string $version, Carbon $startedAt): bool
    {
        $connection = Cache::get($node->cacheKey());
        $connectedAt = data_get($connection, 'last_connected_at');
        $capabilities = data_get($connection, 'capabilities');
        if (data_get($connection, 'status') !== 'connected' || data_get($connection, 'sentinel_version') !== $version || ! is_string($connectedAt) || ! is_array($capabilities) || $capabilities === []) {
            return false;
        }

        try {
            return Carbon::parse($connectedAt)->greaterThanOrEqualTo($startedAt);
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $result */
    private function fail(NodeOperation $operation, string $error, array $result): NodeOperation
    {
        $operation->refresh();
        if ($operation->status->isFinal()) {
            return $operation;
        }

        return TransitionOperation::run($operation, NodeOperationStatus::FAILED, result: $result, error: mb_substr(FetchNodeLogs::redact($error), 0, 2000));
    }
}
