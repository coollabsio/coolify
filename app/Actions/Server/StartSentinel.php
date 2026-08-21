<?php

namespace App\Actions\Server;

use App\Events\SentinelRestarted;
use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

class StartSentinel
{
    use AsAction;

    public static function sentinelTrafficEnvironment(Server $server): array
    {
        if (! $server->isTrafficAnalyticsEnabled()) {
            return [];
        }

        $logPath = rtrim($server->proxyPath(), '/').'/access.log';
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
        ];
        $license = data_get($settings, 'geoip_maxmind_license_key');
        if ($settings->is_geoip_enabled && filled($license)) {
            $env['GEOIP_MAXMIND_LICENSE_KEY'] = $license;
        }

        return $env;
    }

    public function handle(Server $server, bool $restart = false, ?string $latestVersion = null, ?string $customImage = null)
    {
        if ($server->isSwarm() || $server->isBuildServer()) {
            return;
        }
        if ($restart) {
            StopSentinel::run($server);
        }
        $version = $latestVersion ?? get_latest_sentinel_version();
        $metricsHistory = data_get($server, 'settings.sentinel_metrics_history_days');
        $refreshRate = data_get($server, 'settings.sentinel_metrics_refresh_rate_seconds');
        $pushInterval = data_get($server, 'settings.sentinel_push_interval_seconds');
        $token = $server->settings->ensureValidSentinelToken();
        $endpoint = $server->settings->ensureSentinelUrl();
        $debug = data_get($server, 'settings.is_sentinel_debug_enabled');
        $mountDir = '/data/coolify/sentinel';
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
            $mountDir = '/var/lib/docker/volumes/coolify_dev_coolify_data/_data/sentinel';
        }
        $dockerEnvironments = implode(' ', array_map(fn ($key, $value) => '-e '.escapeshellarg("$key=$value"), array_keys($environments), $environments));
        $dockerLabels = implode(' ', array_map(fn ($key, $value) => "$key=$value", array_keys($labels), $labels));
        $trafficMount = $server->isTrafficAnalyticsEnabled()
            ? '-v '.escapeshellarg($server->proxyPath().':'.$server->proxyPath().':ro').' '
            : '';
        $dockerCommand = "docker run -d $dockerEnvironments --name coolify-sentinel -v /var/run/docker.sock:/var/run/docker.sock -v $mountDir:/app/db {$trafficMount}--pid host --health-cmd \"curl --fail http://127.0.0.1:8888/api/health || exit 1\" --health-interval 10s --health-retries 3 --add-host=host.docker.internal:host-gateway --label $dockerLabels $image";

        instant_remote_process([
            'docker rm -f coolify-sentinel || true',
            "mkdir -p $mountDir",
            $dockerCommand,
            "chown -R 9999:root $mountDir",
            "chmod -R 700 $mountDir",
        ], $server);

        $server->settings->is_sentinel_enabled = true;
        $server->settings->save();
        $server->sentinelHeartbeat();

        // Dispatch event to notify UI components
        SentinelRestarted::dispatch($server, $version);
    }
}
