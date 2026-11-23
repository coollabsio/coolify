<?php

namespace App\Actions\Server;

use App\Events\SentinelSynchronized;
use App\Jobs\CheckAndStartSentinelJob;
use App\Jobs\PushServerUpdateJob;
use App\Models\Server;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Handles one authenticated Sentinel report, whether Sentinel pushed it or Coolify pulled it over SSH.
 *
 * Sentinel reports its full container list on a fixed interval (default 60s),
 * even when nothing changed. To avoid dispatching one PushServerUpdateJob per
 * server per minute, the job is only dispatched when the container state hash
 * changes, or when the force window has elapsed.
 */
class ProcessSentinelReport
{
    use AsAction;

    /**
     * @throws ValidationException when the payload has no containers array
     */
    public function handle(Server $server, array $data, ?string $version): void
    {
        $validator = Validator::make($data, [
            'containers' => ['present', 'array'],
        ]);

        if ($validator->fails()) {
            $server->rememberSentinelPushProblem('Coolify rejected the push: the payload is not valid.');

            throw new ValidationException($validator);
        }

        $wasSentinelLive = $server->sentinel_updated_at !== null && $server->isSentinelLive();

        // Heartbeat MUST update on every report — drives isSentinelLive() and SSH-check skipping.
        $server->sentinel_waiting_since = null;
        $server->sentinelHeartbeat();

        if (! $wasSentinelLive) {
            $server->rememberSentinelPushProblem(null);
            SentinelSynchronized::dispatch($server);
        }

        $this->updateSentinelIfOutdated($server, $version);

        if ($this->shouldDispatchUpdate($server, $data)) {
            PushServerUpdateJob::dispatch($server, $data);
        }
    }

    /**
     * Sentinel sends its version with every report, so an outdated Sentinel is found without SSH.
     * The reported version also tells ServerManagerJob to skip its hourly SSH version check.
     * Old Sentinel versions do not send the version and keep the hourly SSH check.
     */
    private function updateSentinelIfOutdated(Server $server, ?string $runningVersion): void
    {
        if (! is_string($runningVersion) || preg_match('/^\d+\.\d+\.\d+$/', $runningVersion) !== 1) {
            return;
        }

        Cache::put(Server::sentinelReportedVersionCacheKey($server->id), $runningVersion, now()->addHours(2));

        $latestVersion = data_get(get_versions_data(), 'sentinel.version');
        if (blank($latestVersion) || version_compare($runningVersion, $latestVersion, '>=')) {
            return;
        }

        // Retry at most hourly if an update fails.
        if (Cache::add("sentinel:update-dispatched:{$server->id}", true, 3600)) {
            CheckAndStartSentinelJob::dispatch($server);
        }
    }

    /**
     * Decide whether PushServerUpdateJob should be dispatched for this report.
     *
     * Dispatches when: first report (no cached hash), the container state changed,
     * or the force window elapsed.
     */
    private function shouldDispatchUpdate(Server $server, array $data): bool
    {
        $hash = $this->containerStateHash($data);
        $hashKey = "sentinel:push-hash:{$server->id}";
        $forceKey = "sentinel:push-force:{$server->id}";
        $lockKey = "sentinel:push-lock:{$server->id}";

        try {
            return Cache::lock($lockKey, 10)->block(5, function () use ($hashKey, $forceKey, $hash): bool {
                $cachedHash = Cache::get($hashKey);
                $forceActive = Cache::has($forceKey);

                $shouldDispatch = $cachedHash === null || $cachedHash !== $hash || ! $forceActive;

                if ($shouldDispatch) {
                    // Day-long TTL bounds memory if a server stops reporting entirely.
                    Cache::put($hashKey, $hash, now()->addDay());
                    Cache::put($forceKey, true, config('constants.sentinel.push_force_interval_seconds', 300));
                }

                return $shouldDispatch;
            });
        } catch (LockTimeoutException) {
            return false;
        }
    }

    /**
     * Build a stable hash of container state.
     *
     * Covers [name, state, health_status, restart_count] only — metrics and
     * filesystem_usage_root are excluded on purpose because disk % churns
     * constantly and would dispatch PushServerUpdateJob on every report. Health is
     * included so a healthy/unhealthy change reaches the UI on the next report
     * instead of after the force window. The snapshot completeness flag is
     * included so a complete snapshot always dispatches after a partial
     * snapshot. Sorted by name so container ordering from Sentinel does not
     * affect the hash.
     */
    private function containerStateHash(array $data): string
    {
        $containers = collect(data_get($data, 'containers', []))
            ->map(fn ($c) => [
                'name' => data_get($c, 'name'),
                'state' => data_get($c, 'state'),
                'health_status' => data_get($c, 'health_status'),
                'restart_count' => data_get($c, 'restart_count'),
            ])
            ->sortBy('name')
            ->values()
            ->all();

        return hash('xxh128', json_encode([
            'snapshot_complete' => $this->isCompleteSnapshot($data),
            'containers' => $containers,
        ]));
    }

    private function isCompleteSnapshot(array $data): bool
    {
        return data_get($data, 'snapshot.complete', true) !== false;
    }
}
