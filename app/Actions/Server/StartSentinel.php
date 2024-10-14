<?php

namespace App\Actions\Server;

use App\Models\InstanceSettings;
use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

class StartSentinel
{
    use AsAction;

    public function handle(Server $server, $version = 'latest', bool $restart = false)
    {
        if ($restart) {
            StopSentinel::run($server);
        }
        $metrics_history = $server->settings->metrics_history_days;
        $refresh_rate = $server->settings->metrics_refresh_rate_seconds;
        $token = $server->settings->sentinel_token;
        $fqdn = InstanceSettings::get()->fqdn;
        if (str($fqdn)->startsWith('http')) {
            throw new \Exception('You should use https to run Sentinel.');
        }
        $environments = [
            'TOKEN' => $token,
            'ENDPOINT' => InstanceSettings::get()->fqdn,
            'COLLECTOR_ENABLED' => 'true',
            'COLLECTOR_REFRESH_RATE_SECONDS' => $refresh_rate,
            'COLLECTOR_RETENTION_PERIOD_DAYS' => $metrics_history
        ];
        $docker_environments = "-e \"" . implode("\" -e \"", array_map(fn($key, $value) => "$key=$value", array_keys($environments), $environments)) . "\"";
        ray($docker_environments);
        return true;
        // instant_remote_process([
        //     "docker run --rm --pull always -d $docker_environments --name coolify-sentinel -v /var/run/docker.sock:/var/run/docker.sock -v /data/coolify/sentinel:/app/sentinel --pid host --health-cmd \"curl --fail http://127.0.0.1:8888/api/health || exit 1\" --health-interval 10s --health-retries 3 ghcr.io/coollabsio/sentinel:$version",
        //     'chown -R 9999:root /data/coolify/sentinel',
        //     'chmod -R 700 /data/coolify/sentinel',
        // ], $server, true);
    }
}
