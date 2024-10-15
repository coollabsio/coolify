<?php

namespace App\Actions\Server;

use App\Models\InstanceSettings;
use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

class StartSentinel
{
    use AsAction;

    public function handle(Server $server, $version = 'next', bool $restart = false)
    {
        if ($restart) {
            StopSentinel::run($server);
        }
        $metrics_history = data_get($server, 'settings.sentinel_metrics_history_days');
        $refresh_rate = data_get($server, 'settings.sentinel_metrics_refresh_rate_seconds');
        $push_interval = data_get($server, 'settings.sentinel_push_interval_seconds');
        $token = data_get($server, 'settings.sentinel_token');
        $endpoint = data_get($server, 'settings.sentinel_custom_url');
        $mount_dir = '/data/coolify/sentinel';
        $image = "ghcr.io/coollabsio/sentinel:$version";
        if (! $endpoint) {
            throw new \Exception('You should set FQDN in Instance Settings.');
        }
        $environments = [
            'TOKEN' => $token,
            'PUSH_ENDPOINT' => $endpoint,
            'PUSH_INTERVAL_SECONDS' => $push_interval,
            'COLLECTOR_ENABLED' => $server->isMetricsEnabled() ? 'true' : 'false',
            'COLLECTOR_REFRESH_RATE_SECONDS' => $refresh_rate,
            'COLLECTOR_RETENTION_PERIOD_DAYS' => $metrics_history,
        ];
        if (isDev()) {
            data_set($environments, 'DEBUG', 'true');
            $mount_dir = '/var/lib/docker/volumes/coolify_dev_coolify_data/_data/sentinel';
            $image = 'sentinel';
        }
        $docker_environments = '-e "' . implode('" -e "', array_map(fn($key, $value) => "$key=$value", array_keys($environments), $environments)) . '"';

        $docker_command = "docker run -d $docker_environments --name coolify-sentinel -v /var/run/docker.sock:/var/run/docker.sock -v $mount_dir:/app/db --pid host --health-cmd \"curl --fail http://127.0.0.1:8888/api/health || exit 1\" --health-interval 10s --health-retries 3 --add-host=host.docker.internal:host-gateway $image";

        instant_remote_process([
            'docker rm -f coolify-sentinel || true',
            "mkdir -p $mount_dir",
            $docker_command,
            "chown -R 9999:root $mount_dir",
            "chmod -R 700 $mount_dir",
        ], $server);

        $server->settings->is_sentinel_enabled = true;
        $server->settings->save();
        $server->sentinelUpdateAt();
    }
}
