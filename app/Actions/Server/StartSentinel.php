<?php

namespace App\Actions\Server;

use Lorisleiva\Actions\Concerns\AsAction;
use App\Models\Server;

class StartSentinel
{
    use AsAction;
    public function handle(Server $server)
    {
        return instant_remote_process(['docker run --rm --pull always -d --name coolify-sentinel --restart always -p 127.0.0.1:8888:8888 -v /var/run/docker.sock:/var/run/docker.sock -v /data/coolify/metrics:/var/www/html/storage/app/metrics --pid host --health-cmd "curl --fail http://127.0.0.1:8888/api/health || exit 1" --health-interval 10s --health-retries 3 ghcr.io/coollabsio/sentinel:latest'], $server, false);
    }
}
