<?php

namespace App\Jobs;

use App\Actions\Server\StartSentinel;
use App\Enums\SentinelRuntime;
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
        $runtime = StartSentinel::actualRuntime($this->server);

        // A change of the instance setting, or a cached host failure, moves the server to the other runtime.
        if (StartSentinel::wantedRuntime($this->server) !== $runtime) {
            $this->startSentinel($latestVersion);

            return;
        }

        if ($runtime === SentinelRuntime::Host) {
            // `is-active` exits non-zero for a stopped unit, so `|| true` keeps that apart from an SSH failure, which throws.
            $serviceState = trim((string) instant_remote_process_with_timeout(['systemctl is-active sentinel.service || true'], $this->server));
            // systemd restarts a crashed service (Restart=always); it is `activating` until then.
            if (! in_array($serviceState, ['active', 'activating'], true)) {
                $this->startSentinel($latestVersion);

                return;
            }
        } else {
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
        }

        // One SSH call reads the version and the last push result. Sentinel versions without
        // /api/push-status give an empty second line. The token is in a heredoc, so it is not a process argument.
        $token = $this->server->settings->ensureValidSentinelToken();
        $script = implode("\n", [
            'curl -s http://127.0.0.1:8888/api/version',
            'echo',
            "curl -sf -H @- http://127.0.0.1:8888/api/push-status <<'COOLIFY_SENTINEL_AUTH'",
            "Authorization: Bearer {$token}",
            'COOLIFY_SENTINEL_AUTH',
            'true',
        ]);
        $output = instant_remote_process_with_timeout([
            "{$runtime->shellCommand()} <<'COOLIFY_SENTINEL_SCRIPT'\n{$script}\nCOOLIFY_SENTINEL_SCRIPT",
        ], $this->server, false);
        [$runningVersion, $pushStatus] = array_pad(explode("\n", trim((string) $output), 2), 2, null);
        $this->rememberPushError($pushStatus);

        // The host binary comes from its own pinned image, so it is compared with its own version.
        $wantedVersion = $runtime === SentinelRuntime::Host ? StartSentinel::latestHostVersion() : $latestVersion;

        // An empty version means that the API does not answer yet, for example while Sentinel starts.
        // A container whose API stays down fails its health check and is restarted above. A host service
        // whose API stays down does not push, so the stall check below restarts it.
        if (filled($runningVersion) && version_compare(trim($runningVersion), $wantedVersion, '<')) {
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
