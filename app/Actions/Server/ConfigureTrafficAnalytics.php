<?php

namespace App\Actions\Server;

use App\Actions\Proxy\GetProxyConfiguration;
use App\Jobs\RestartProxyJob;
use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

class ConfigureTrafficAnalytics
{
    use AsAction;

    public function handle(Server $server, bool $enable): void
    {
        $server->settings->is_traffic_analytics_enabled = $enable;
        $server->settings->save();
        $server->refresh();

        // Regenerate proxy config so the (Traefik) access-log flags / (Caddy) log labels take effect.
        GetProxyConfiguration::run($server, forceRegenerate: true);
        RestartProxyJob::dispatch($server);

        // Recreate Sentinel with the traffic env + proxy-log mount (or without, when disabling).
        StartSentinel::run($server, restart: true);
    }
}
