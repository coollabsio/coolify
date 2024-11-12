<?php

namespace App\Actions\Server;

use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

class StartSentinel
{
    use AsAction;

    public function handle(Server $server, bool $restart = false, ?string $latestVersion = null)
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
        $token = data_get($server, 'settings.sentinel_token');
        $endpoint = data_get($server, 'settings.sentinel_custom_url');
        $debug = data_get($server, 'settings.is_sentinel_debug_enabled');
        $mountDir = '/data/coolify/sentinel';
        $image = "ghcr.io/coollabsio/sentinel:$version";
        if (! $endpoint) {
            throw new \Exception('You should set FQDN in Instance Settings.');
        }
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
            // $image = 'sentinel';
            $mountDir = '/var/lib/docker/volumes/coolify_dev_coolify_data/_data/sentinel';
        }
        $dockerEnvironments = '-e "'.implode('" -e "', array_map(fn ($key, $value) => "$key=$value", array_keys($environments), $environments)).'"';
        $dockerLabels = implode(' ', array_map(fn ($key, $value) => "$key=$value", array_keys($labels), $labels));
        $dockerCommand = "docker run -d $dockerEnvironments --name coolify-sentinel -v /var/run/docker.sock:/var/run/docker.sock -v $mountDir:/app/db --pid host --health-cmd \"curl --fail http://127.0.0.1:8888/api/health || exit 1\" --health-interval 10s --health-retries 3 --add-host=host.docker.internal:host-gateway --label $dockerLabels $image";

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
    }
}
