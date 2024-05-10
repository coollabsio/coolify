<?php

namespace App\Actions\Server;

use Lorisleiva\Actions\Concerns\AsAction;
use App\Models\Server;

class StartSentinel
{
    use AsAction;
    public function handle(Server $server, $version = 'latest', bool $restart = false)
    {
        if ($restart) {
            instant_remote_process(['docker rm -f coolify-sentinel'], $server, false);
        }
        instant_remote_process([
            "docker run --rm --pull always -d -e \"SCHEDULER=true\" --name coolify-sentinel -v /var/run/docker.sock:/var/run/docker.sock -v /data/coolify/metrics:/app/metrics -v /data/coolify/logs:/app/logs --pid host --health-cmd \"curl --fail http://127.0.0.1:8888/api/health || exit 1\" --health-interval 10s --health-retries 3 ghcr.io/coollabsio/sentinel:$version",
            "chown -R 9999:root /data/coolify/metrics /data/coolify/logs",
            "chmod -R 700 /data/coolify/metrics /data/coolify/logs"
        ], $server, false);
    }
}
