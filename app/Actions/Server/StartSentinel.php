<?php

namespace App\Actions\Server;

use App\Enums\SentinelRuntime;
use App\Enums\TrafficIpMode;
use App\Events\SentinelRestarted;
use App\Models\InstanceSettings;
use App\Models\Server;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Lorisleiva\Actions\Concerns\AsAction;

class StartSentinel
{
    use AsAction;

    /** Longer than one start, including the image pull. */
    public const LOCK_SECONDS = 300;

    /** Shorter than the 120 second timeout of CheckAndStartSentinelJob. */
    public const LOCK_WAIT_SECONDS = 90;

    /** A host whose install failed is not tried again with the same image for this long. */
    public const HOST_RETRY_AFTER_SECONDS = 86400;

    public static function lockKey(Server $server): string
    {
        return 'sentinel-start:'.$server->uuid;
    }

    /**
     * The pinned image that contains the host binary, or null when the versions data has no digest.
     * The image is published only to GHCR, so it does not use the Docker registry setting.
     */
    public static function hostImage(): ?string
    {
        $digest = data_get(get_versions_data(), 'sentinel-host.digest');

        return filled($digest) ? 'ghcr.io/coollabsio/sentinel-host@'.$digest : null;
    }

    public static function latestHostVersion(): string
    {
        return data_get(get_versions_data(), 'sentinel-host.version') ?? get_latest_sentinel_version();
    }

    public static function hostUnsupportedKey(Server $server, string $image): string
    {
        return "sentinel:host-unsupported:{$server->id}:{$image}";
    }

    public static function hostFailedKey(Server $server, string $image): string
    {
        return "sentinel:host-failed:{$server->id}:{$image}";
    }

    /**
     * The runtime that Sentinel should use on the server.
     * The localhost push URL `http://coolify:8080` resolves only on the Docker network, so localhost stays on Docker.
     * A cached failure for the current image keeps the server on Docker, so the reconcile loop does not retry every check.
     */
    public static function wantedRuntime(Server $server): SentinelRuntime
    {
        if (InstanceSettings::get()->sentinel_runtime_mode !== SentinelRuntime::Host
            || $server->isLocalhost() || $server->isSwarm() || $server->isBuildServer()) {
            return SentinelRuntime::Docker;
        }

        $image = self::hostImage();
        if ($image === null || Cache::has(self::hostUnsupportedKey($server, $image)) || Cache::has(self::hostFailedKey($server, $image))) {
            return SentinelRuntime::Docker;
        }

        return SentinelRuntime::Host;
    }

    public static function actualRuntime(Server $server): SentinelRuntime
    {
        return $server->settings?->sentinel_runtime ?? SentinelRuntime::Docker;
    }

    /**
     * Sentinel and the proxy both mount this host path, and Sentinel reads the access log at the same path.
     */
    public static function trafficLogDirectory(Server $server): string
    {
        return devHostDockerPath($server, rtrim($server->proxyPath(), '/'));
    }

    /**
     * Sentinel reads the proxy access log only when analytics is on and a Traefik or Caddy proxy writes it.
     * After a switch to a proxy without analytics support, Sentinel must not keep the old log mount.
     */
    public static function collectsTraffic(Server $server): bool
    {
        return $server->isTrafficAnalyticsEnabled() && $server->hasTrafficAnalyticsProxy();
    }

    public static function sentinelTrafficEnvironment(Server $server): array
    {
        if (! self::collectsTraffic($server)) {
            return [];
        }

        $logPath = self::trafficLogDirectory($server).'/access.log';
        $settings = $server->settings;
        $env = [
            'TRAFFIC_ENABLED' => 'true',
            'TRAFFIC_PROXY_TYPE' => 'auto',
            'TRAFFIC_ACCESS_LOG_PATH' => $logPath,
            'TRAFFIC_TOPN' => (string) ($settings->traffic_topn ?: 50),
            'TRAFFIC_SAMPLE_THRESHOLD' => (string) ($settings->traffic_sample_threshold ?? 0),
            'TRAFFIC_RETENTION_1H_DAYS' => (string) ($settings->traffic_retention_1h_days ?: 30),
            'TRAFFIC_RETENTION_1D_DAYS' => (string) ($settings->traffic_retention_1d_days ?: 395),
            'GEOIP_ENABLED' => $settings->is_geoip_enabled ? 'true' : 'false',
            'GEOIP_REFRESH_DAYS' => (string) ($settings->geoip_refresh_days ?: 30),
            'TRAFFIC_IP_MODE' => TrafficIpMode::forServer($server)->value,
        ];
        $license = data_get($settings, 'geoip_maxmind_license_key');
        if ($settings->is_geoip_enabled && filled($license)) {
            $env['GEOIP_MAXMIND_LICENSE_KEY'] = $license;
        }

        return $env;
    }

    /**
     * Sentinel opens the access log once at startup and never retries, so the file must exist
     * before the container starts. `touch` keeps an existing log intact.
     *
     * @return array<int, string>
     */
    public static function trafficLogPreparationCommands(Server $server): array
    {
        if (! $server->isTrafficAnalyticsEnabled() || ! $server->supportsTrafficAnalytics()) {
            return [];
        }

        $directory = self::trafficLogDirectory($server);

        return [
            'mkdir -p '.escapeshellarg($directory),
            'touch '.escapeshellarg($directory.'/access.log'),
        ];
    }

    /**
     * Docker reads an env file line by line, so a line break in a value would add a variable.
     *
     * @param  array<string, mixed>  $environments
     */
    public static function envFileContent(array $environments): string
    {
        $lines = [];
        foreach ($environments as $key => $value) {
            if (preg_match('/[\r\n]/', (string) $value)) {
                throw new \RuntimeException("The Sentinel setting {$key} must not contain a line break.");
            }
            $lines[] = "{$key}={$value}";
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Each start replaces the running Sentinel in both runtimes, so `$restart` changes nothing. It stays for the callers.
     *
     * @throws LockTimeoutException when another start for the server does not finish in time
     */
    public function handle(Server $server, bool $restart = false, ?string $latestVersion = null, ?string $customImage = null): void
    {
        if ($server->isSwarm() || $server->isBuildServer()) {
            return;
        }

        // Two starts at the same time both remove the container, and then the second `docker run`
        // fails because the first one already uses the coolify-sentinel name.
        Cache::lock(self::lockKey($server), self::LOCK_SECONDS)->block(
            self::LOCK_WAIT_SECONDS,
            fn () => $this->start($server, $restart, $latestVersion, $customImage),
        );
    }

    private function start(Server $server, bool $restart, ?string $latestVersion, ?string $customImage): void
    {
        // A start that waited for the lock must use the settings saved in the meantime.
        $server->refresh();
        $version = $latestVersion ?? get_latest_sentinel_version();
        $metricsHistory = data_get($server, 'settings.sentinel_metrics_history_days');
        $refreshRate = data_get($server, 'settings.sentinel_metrics_refresh_rate_seconds');
        $pushInterval = data_get($server, 'settings.sentinel_push_interval_seconds');
        $token = $server->settings->ensureValidSentinelToken();
        $endpoint = $server->settings->ensureSentinelUrl();
        $debug = data_get($server, 'settings.is_sentinel_debug_enabled');
        $mountDir = devHostDockerPath($server, base_configuration_dir().'/sentinel');
        $image = coolifyRegistryUrl().'/coollabsio/sentinel:'.$version;
        $environments = [
            'TOKEN' => $token,
            'DEBUG' => $debug ? 'true' : 'false',
            'PUSH_ENDPOINT' => $endpoint,
            'PUSH_INTERVAL_SECONDS' => $pushInterval,
            'COLLECTOR_ENABLED' => $server->isMetricsEnabled() ? 'true' : 'false',
            'COLLECTOR_REFRESH_RATE_SECONDS' => $refreshRate,
            'COLLECTOR_RETENTION_PERIOD_DAYS' => $metricsHistory,
        ];
        $environments = array_merge($environments, self::sentinelTrafficEnvironment($server));
        $labels = [
            'coolify.managed' => 'true',
        ];
        if (isDev()) {
            // data_set($environments, 'DEBUG', 'true');
            if ($customImage && ! empty($customImage)) {
                $image = $customImage;
            }
        }
        $envFile = devHostDockerPath($server, base_configuration_dir().'/sentinel.env');
        $envFileContent = self::envFileContent($environments);
        $dockerLabels = implode(' ', array_map(fn ($key, $value) => "$key=$value", array_keys($labels), $labels));
        $trafficLogDirectory = self::trafficLogDirectory($server);
        $trafficMount = self::collectsTraffic($server)
            ? '-v '.escapeshellarg("{$trafficLogDirectory}:{$trafficLogDirectory}:ro").' '
            : '';
        $network = $server->isLocalhost() ? ' --network coolify' : '';
        // A userns-remap daemon refuses --pid host unless the container opts out of remapping.
        // On other daemons --userns host has no effect. https://docs.docker.com/engine/security/userns-remap/
        $dockerCommand = "docker run -d$network --env-file $envFile --name coolify-sentinel --restart unless-stopped -v /var/run/docker.sock:/var/run/docker.sock -v $mountDir:/app/db {$trafficMount}--userns host --pid host --health-cmd \"curl --fail http://127.0.0.1:8888/api/health || exit 1\" --health-start-period 120s --health-interval 10s --health-retries 3 --add-host=host.docker.internal:host-gateway --label $dockerLabels $image";

        $server->sentinelHeartbeat(isReset: true);
        $server->forceFill(['sentinel_waiting_since' => now()])->save();

        // A custom image is a Docker image, so it always runs in the container.
        $wantsHost = blank($customImage) && self::wantedRuntime($server) === SentinelRuntime::Host;
        if ($wantsHost && $this->startOnHost($server, $environments)) {
            $this->finishStart($server, self::latestHostVersion());

            return;
        }

        // Only one process may use the SQLite files in the data directory.
        if (self::actualRuntime($server) === SentinelRuntime::Host) {
            RemoveHostSentinel::run($server);
        }

        instant_remote_process([
            'docker rm -f coolify-sentinel || true',
            "mkdir -p $mountDir",
            ...self::trafficLogPreparationCommands($server),
            'sh -ec '.escapeshellarg(implode("\n", [
                'trap '.escapeshellarg("rm -f $envFile").' EXIT',
                // The mode is set before the token is written, so it does not depend on the umask of the server.
                "rm -f $envFile",
                "touch $envFile",
                "chmod 600 $envFile",
                "echo '".base64_encode($envFileContent)."' | base64 -d | tee $envFile > /dev/null",
                $dockerCommand,
                // Docker keeps the environment in the container configuration, so restarts do not read the file again.
                "rm -f $envFile",
                'trap - EXIT',
            ])),
            "chown -R 9999:root $mountDir",
            "chmod -R 700 $mountDir",
        ], $server);

        if ($server->settings->sentinel_runtime !== SentinelRuntime::Docker) {
            $server->settings->forceFill(['sentinel_runtime' => SentinelRuntime::Docker])->save();
        }

        $this->finishStart($server, $version);
    }

    /**
     * Installs the host service. On false, the server stays on Docker for this image (unsupported) or for HOST_RETRY_AFTER_SECONDS (failed), and the caller starts the container.
     *
     * @param  array<string, mixed>  $environments
     */
    private function startOnHost(Server $server, array $environments): bool
    {
        $image = self::hostImage();

        try {
            $preparation = self::trafficLogPreparationCommands($server);
            if ($preparation !== []) {
                instant_remote_process($preparation, $server);
            }
            if (! InstallHostSentinel::run($server, $environments, $image)) {
                // Each failed try restarts the container, so a host without systemd is tried again only with a new image.
                Cache::forever(self::hostUnsupportedKey($server, $image), true);

                return false;
            }
        } catch (\Throwable $e) {
            Cache::put(self::hostFailedKey($server, $image), true, self::HOST_RETRY_AFTER_SECONDS);
            logger()->warning('Sentinel could not start as a host service, so it runs in the Docker container', [
                'server_id' => $server->id,
                'error' => $e->getMessage(),
                'output' => $e->getPrevious()?->getMessage(),
            ]);
            $server->rememberSentinelPushProblem($e->getMessage().' Sentinel runs in the Docker container. Coolify tries the host service again in one day.');

            return false;
        }

        $server->settings->forceFill(['sentinel_runtime' => SentinelRuntime::Host])->save();

        return true;
    }

    private function finishStart(Server $server, string $version): void
    {
        $server->settings->is_sentinel_enabled = true;
        $server->settings->save();
        $server->refresh();
        if ($server->sentinel_waiting_since !== null) {
            $server->forceFill(['sentinel_waiting_since' => now()])->save();
        }

        // Dispatch event to notify UI components
        SentinelRestarted::dispatch($server, $version);
    }
}
