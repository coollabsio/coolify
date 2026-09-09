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
        $sentinelWasEnabled = (bool) $server->settings->is_sentinel_enabled;

        $server->settings->is_traffic_analytics_enabled = $enable;
        $server->settings->save();
        $server->refresh();

        // Regenerate proxy config so the (Traefik) access-log flags / (Caddy) log labels take effect.
        GetProxyConfiguration::run($server, forceRegenerate: true);
        RestartProxyJob::dispatch($server);

        // Recreate Sentinel so it picks up (enabling) or drops (disabling) the traffic env + proxy-log mount.
        // Enabling analytics needs Sentinel running; when disabling, only restart if Sentinel was already
        // enabled so we never turn Sentinel on as a side effect of disabling analytics.
        if ($enable || $sentinelWasEnabled) {
            StartSentinel::run($server, restart: true);
        }
    }
}
