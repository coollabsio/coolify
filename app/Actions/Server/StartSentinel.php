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
        $metrics_history = $server->settings->sentinel_metrics_history_days;
        $refresh_rate = $server->settings->sentinel_metrics_refresh_rate_seconds;
        $token = $server->settings->sentinel_token;
        $endpoint = InstanceSettings::get()->fqdn;
        if (isDev()) {
            $endpoint = 'http://host.docker.internal:8000';
        } else {
            if (str($endpoint)->startsWith('http')) {
                throw new \Exception('You should use https to run Sentinel.');
            }
        }
        if (! $endpoint) {
            throw new \Exception('You should set FQDN in Instance Settings.');
        }
        $environments = [
            'TOKEN' => $token,
            'ENDPOINT' => $endpoint,
            'COLLECTOR_ENABLED' => 'true',
            'COLLECTOR_REFRESH_RATE_SECONDS' => $refresh_rate,
            'COLLECTOR_RETENTION_PERIOD_DAYS' => $metrics_history,
        ];
        if (isDev()) {
            data_set($environments, 'GIN_MODE', 'debug');
        }
        $mount_dir = '/data/coolify/sentinel';
        if (isDev()) {
            $mount_dir = '/var/lib/docker/volumes/coolify_dev_coolify_data/_data/sentinel';
        }
        $docker_environments = '-e "'.implode('" -e "', array_map(fn ($key, $value) => "$key=$value", array_keys($environments), $environments)).'"';
        $docker_command = "docker run --pull always --rm -d $docker_environments --name coolify-sentinel -v /var/run/docker.sock:/var/run/docker.sock -v $mount_dir:/app/db --pid host --health-cmd \"curl --fail http://127.0.0.1:8888/api/health || exit 1\" --health-interval 10s --health-retries 3 --add-host=host.docker.internal:host-gateway ghcr.io/coollabsio/sentinel:$version";

        return instant_remote_process([
            'docker rm -f coolify-sentinel || true',
            "mkdir -p $mount_dir",
            $docker_command,
            "chown -R 9999:root $mount_dir",
            "chmod -R 700 $mount_dir",
        ], $server, true);
    }
}
