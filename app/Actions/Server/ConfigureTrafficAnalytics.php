<?php

namespace App\Actions\Server;

use App\Actions\Proxy\GetProxyConfiguration;
use App\Actions\Proxy\SaveProxyConfiguration;
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

        $configuration = GetProxyConfiguration::run($server);
        $configuration = applyTrafficAnalyticsToProxyConfiguration($server, $configuration);
        SaveProxyConfiguration::run($server, $configuration);
        RestartProxyJob::dispatch($server);

        // Recreate Sentinel so it picks up (enabling) or drops (disabling) the traffic env + proxy-log mount.
        // Enabling analytics needs Sentinel running; when disabling, only restart if Sentinel was already
        // enabled so we never turn Sentinel on as a side effect of disabling analytics.
        if ($enable || $sentinelWasEnabled) {
            StartSentinel::run($server, restart: true);
        }
    }
}
