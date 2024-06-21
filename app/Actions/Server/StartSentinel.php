<?php

namespace App\Actions\Server;

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
        $token = $server->settings->metrics_token;
        instant_remote_process([
            "docker run --rm --pull always -d -e \"TOKEN={$token}\" -e \"SCHEDULER=true\" -e \"METRICS_HISTORY={$metrics_history}\" -e \"REFRESH_RATE={$refresh_rate}\" --name coolify-sentinel -v /var/run/docker.sock:/var/run/docker.sock -v /data/coolify/metrics:/app/metrics -v /data/coolify/logs:/app/logs --pid host --health-cmd \"curl --fail http://127.0.0.1:8888/api/health || exit 1\" --health-interval 10s --health-retries 3 ghcr.io/coollabsio/sentinel:$version",
            'chown -R 9999:root /data/coolify/metrics /data/coolify/logs',
            'chmod -R 700 /data/coolify/metrics /data/coolify/logs',
        ], $server, true);
    }
}
