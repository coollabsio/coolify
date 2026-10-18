<?php

namespace App\Jobs;

use App\Actions\Server\StartSentinel;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class CheckAndStartSentinelJob implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * A running Sentinel that has not pushed for this long is restarted with the current settings.
     * This repairs a changed Coolify URL or token, and a hung push loop.
     */
    public const STALL_RESTART_AFTER_SECONDS = 600;

    /** A stalled Sentinel is restarted at most once in this period, because a firewall problem stays after a restart. */
    public const STALL_RESTART_INTERVAL_SECONDS = 3600;

    public $timeout = 120;

    /**
     * Only one check per server can wait in the queue, so a slow queue cannot collect copies.
     */
    public $uniqueFor = 600;

    public function __construct(public Server $server) {}

    public function uniqueId(): string
    {
        return $this->server->uuid;
    }

    public function handle(): void
    {
        if (! $this->sentinelIsEnabled() || ! $this->server->isFunctional()) {
            return;
        }

        $latestVersion = get_latest_sentinel_version();

        // An SSH failure throws here, so it is never mistaken for a missing container.
        // A missing container gives empty output and exit code 0.
        $sentinelStatus = instant_remote_process_with_timeout(["docker ps -a --filter 'name=^coolify-sentinel$' --format '{{.State}} {{.Status}}'"], $this->server);
        if (! str_starts_with((string) $sentinelStatus, 'running')) {
            $this->startSentinel($latestVersion);

            return;
        }
        if (str_contains($sentinelStatus, '(unhealthy)')) {
            $this->server->rememberSentinelPushProblem('The Sentinel container was unhealthy. Coolify restarted it.');
            $this->startSentinel($latestVersion);

            return;
        }

        // One SSH call reads the version and the last push result. Sentinel versions without
        // /api/push-status give an empty second line.
        $output = instant_remote_process_with_timeout([
            "docker exec coolify-sentinel sh -c 'curl -s http://127.0.0.1:8888/api/version; echo; curl -sf -H \"Authorization: Bearer \$TOKEN\" http://127.0.0.1:8888/api/push-status'",
        ], $this->server, false);
        [$runningVersion, $pushStatus] = array_pad(explode("\n", trim((string) $output), 2), 2, null);
        $this->rememberPushError($pushStatus);

        // An empty version means that the API does not answer yet, for example while Sentinel starts.
        // A Sentinel whose API stays down fails its health check and is restarted above.
        if (filled($runningVersion) && version_compare(trim($runningVersion), $latestVersion, '<')) {
            $this->startSentinel($latestVersion);

            return;
        }

        if ($this->isStalled() && Cache::add("sentinel:stall-restart:{$this->server->id}", true, self::STALL_RESTART_INTERVAL_SECONDS)) {
            $this->startSentinel($latestVersion);
        }
    }

    /**
     * Sentinel is stalled when it has not pushed for STALL_RESTART_AFTER_SECONDS since its last push,
     * or since its last start when it never pushed.
     */
    private function isStalled(): bool
    {
        $lastSignOfLife = $this->server->sentinel_waiting_since ?? $this->server->sentinel_updated_at;
        if ($lastSignOfLife === null) {
            return true;
        }
        $stallAfter = max(self::STALL_RESTART_AFTER_SECONDS, $this->server->waitBeforeDoingSshCheck());

        return Carbon::parse($lastSignOfLife)->isBefore(now()->subSeconds($stallAfter));
    }

    private function rememberPushError(?string $pushStatus): void
    {
        $status = json_decode(trim((string) $pushStatus), true);
        if (! is_array($status) || data_get($status, 'consecutive_failures', 0) < 1 || blank(data_get($status, 'last_error'))) {
            return;
        }

        $this->server->rememberSentinelPushProblem('Sentinel cannot push to Coolify: '.data_get($status, 'last_error'));
    }

    private function sentinelIsEnabled(): bool
    {
        $this->server->unsetRelation('settings');

        return $this->server->isSentinelEnabled();
    }

    private function startSentinel(string $latestVersion): void
    {
        if (! $this->sentinelIsEnabled()) {
            return;
        }

        StartSentinel::run(server: $this->server, restart: true, latestVersion: $latestVersion);
    }
}
