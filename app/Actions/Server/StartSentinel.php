<?php

namespace App\Actions\Server;

use App\Events\SentinelRestarted;
use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

class StartSentinel
{
    use AsAction;

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
        $dockerCommand = "docker run -d $dockerEnvironments --name coolify-sentinel -v /var/run/docker.sock:/var/run/docker.sock -v $mountDir:/app/db --pid host --health-cmd \"curl --fail http://127.0.0.1:8888/api/health || exit 1\" --health-start-period 120s --health-interval 10s --health-retries 3 --add-host=host.docker.internal:host-gateway --label $dockerLabels $image";

        $server->sentinelHeartbeat(isReset: true);

        instant_remote_process([
            'docker rm -f coolify-sentinel || true',
            "mkdir -p $mountDir",
            $dockerCommand,
            "chown -R 9999:root $mountDir",
            "chmod -R 700 $mountDir",
        ], $server);

        $server->settings->is_sentinel_enabled = true;
        $server->settings->save();

        $healthUrl = escapeshellarg(rtrim($endpoint, '/').'/api/health');
        $response = instant_remote_process([
            "docker exec coolify-sentinel curl --fail --silent --show-error --connect-timeout 5 --max-time 10 $healthUrl",
        ], $server, false, timeout: 15);

        if ($response !== 'OK') {
            throw new \RuntimeException('Sentinel cannot reach this Coolify instance. Check the Coolify URL in Sentinel settings, DNS, TLS certificates, and firewall access from the Sentinel container. Check Sentinel logs if health reports still do not arrive.');
        }

        // Dispatch event to notify UI components
        SentinelRestarted::dispatch($server, $version);
    }
}
