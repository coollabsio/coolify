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
        $base_path = config('coolify.coolify_root_path');
        instant_remote_process([
            "docker run --rm --pull always -d -e \"TOKEN={$token}\" -e \"SCHEDULER=true\" -e \"METRICS_HISTORY={$metrics_history}\" -e \"REFRESH_RATE={$refresh_rate}\" --name coolify-sentinel -v /var/run/docker.sock:/var/run/docker.sock -v {$base_path}/metrics:/app/metrics -v {$base_path}/logs:/app/logs --pid host --health-cmd \"curl --fail http://127.0.0.1:8888/api/health || exit 1\" --health-interval 10s --health-retries 3 ghcr.io/coollabsio/sentinel:$version",
            "chown -R 9999:root {$base_path}/metrics {$base_path}/logs",
            "chmod -R 700 {$base_path}/metrics {$base_path}/logs",
        ], $server, true);
    }
}
