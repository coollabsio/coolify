<?php

namespace App\Actions\Node;

use App\Enums\NodeOperationStatus;
use App\Models\Node;
use App\Models\NodeOperation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

/**
 * Upgrades host Sentinel over SSH as a coordinated Node operation. After the install, Sentinel must
 * reconnect to Flux with the expected version, otherwise the previous binary is restored.
 */
class UpgradeSentinel
{
    use AsAction;

    public const COMMAND_TYPE = 'sentinel.upgrade.v1';

    public const HEALTH_CHECK_ATTEMPTS = 30;

    public const HEALTH_CHECK_INTERVAL_SECONDS = 2;

    private const ACTIVE_STATUSES = [
        NodeOperationStatus::QUEUED,
        NodeOperationStatus::DISPATCHED,
        NodeOperationStatus::RUNNING,
        NodeOperationStatus::VERIFYING,
        NodeOperationStatus::UNCERTAIN,
    ];

    public static function upgradeAllCacheKey(int $teamId): string
    {
        return "sentinel-host:upgrade-all:{$teamId}";
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
                try {
                    RollbackSentinel::run($node);
                    $error = "Sentinel {$version} did not reconnect within 60 seconds. The previous version was restored.";
                } catch (Throwable $exception) {
                    $error = "Sentinel {$version} did not reconnect within 60 seconds. Restoring the previous version failed: ".$exception->getMessage();
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
     * Upgrades the team's usable Nodes one at a time and stops at the first failure.
     *
     * @return array{status: string, upgraded: list<string>, failed_node_uuid?: string, failed_node_name?: string, error?: string, finished_at?: string}
     */
    public function upgradeAll(int $teamId, User $user): array
    {
        $summary = ['status' => 'running', 'upgraded' => []];
        Cache::put(self::upgradeAllCacheKey($teamId), $summary, now()->addDay());

        $release = FetchLatestSentinelRelease::run();
        $nodes = $release === null ? collect() : Node::query()
            ->where('team_id', $teamId)
            ->where('is_usable', true)
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->filter(fn (Node $node): bool => $node->needsSentinelUpgrade($release));
        if ($release === null) {
            $summary = [...$summary, 'status' => 'failed', 'error' => 'No valid Sentinel release is available. Try again later.'];
        }

        foreach ($nodes as $node) {
            try {
                if (! $user->can('manageSentinel', $node)) {
                    throw new RuntimeException('You are not allowed to upgrade Sentinel on this server.');
                }
                $operation = $this->handle($this->start($node, $user));
                if ($operation->status !== NodeOperationStatus::SUCCEEDED) {
                    throw new RuntimeException($operation->error ?? 'The Sentinel upgrade did not complete.');
                }
                $summary['upgraded'][] = $node->uuid;
                Cache::put(self::upgradeAllCacheKey($teamId), $summary, now()->addDay());
            } catch (Throwable $exception) {
                $summary = [
                    ...$summary,
                    'status' => 'failed',
                    'failed_node_uuid' => $node->uuid,
                    'failed_node_name' => $node->name,
                    'error' => mb_substr($exception->getMessage(), 0, 2000),
                ];
                break;
            }
        }

        if ($summary['status'] === 'running') {
            $summary['status'] = 'succeeded';
        }
        $summary['finished_at'] = now()->toIso8601String();
        Cache::put(self::upgradeAllCacheKey($teamId), $summary, now()->addDay());

        return $summary;
    }

    public static function isEnabled(): bool
    {
        return isDev() && (bool) config('constants.sentinel.host_enabled', false);
    }

    private function waitForReconnect(Node $node, string $version, Carbon $startedAt): bool
    {
        for ($attempt = 0; $attempt <= self::HEALTH_CHECK_ATTEMPTS; $attempt++) {
            if ($this->reconnectedWithVersion($node, $version, $startedAt)) {
                return true;
            }
            if ($attempt < self::HEALTH_CHECK_ATTEMPTS) {
                Sleep::for(self::HEALTH_CHECK_INTERVAL_SECONDS)->seconds();
            }
        }

        return false;
    }

    private function reconnectedWithVersion(Node $node, string $version, Carbon $startedAt): bool
    {
        $connection = Cache::get($node->cacheKey());
        $connectedAt = data_get($connection, 'last_connected_at');
        if (data_get($connection, 'status') !== 'connected' || data_get($connection, 'sentinel_version') !== $version || ! is_string($connectedAt)) {
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

        return TransitionOperation::run($operation, NodeOperationStatus::FAILED, result: $result, error: mb_substr($error, 0, 2000));
    }
}
