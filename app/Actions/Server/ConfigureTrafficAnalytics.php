<?php

namespace App\Actions\Server;

use App\Actions\Proxy\GetProxyConfiguration;
use App\Actions\Proxy\SaveProxyConfiguration;
use App\Jobs\RestartProxyJob;
use App\Models\Server;
use App\Services\ProxyPortParser;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

class ConfigureTrafficAnalytics
{
    use AsAction;

    private const STOPPED_PROXY_STATUSES = ['exited', 'stopped', 'stopping', 'dead'];

    /**
     * Turn traffic analytics on or off. The proxy configuration is saved before the setting,
     * so a failure leaves both unchanged.
     *
     * @return bool Whether a proxy restart was queued to apply the new configuration.
     */
    public function handle(Server $server, bool $enable): bool
    {
        if ($enable && ($reason = $server->trafficAnalyticsUnsupportedReason()) !== null) {
            throw new RuntimeException($reason);
        }

        $sentinelWasEnabled = (bool) $server->settings->is_sentinel_enabled;

        // Disabling must still work after the proxy was removed, so there may be no proxy configuration to update.
        $hasProxy = $server->hasTrafficAnalyticsProxy();
        if ($hasProxy) {
            $this->saveProxyConfiguration($server, $enable);
        }

        $server->settings->is_traffic_analytics_enabled = $enable;
        $server->settings->save();
        $server->refresh();

        // A proxy the user stopped stays stopped; its next start applies the saved configuration.
        $restartProxy = $hasProxy && ! $this->proxyIsStopped($server);
        if ($restartProxy) {
            RestartProxyJob::dispatch($server);
        }

        // Recreate Sentinel so it picks up (enabling) or drops (disabling) the traffic env + proxy-log mount.
        // Enabling analytics needs Sentinel running; when disabling, only restart if Sentinel was already
        // enabled so we never turn Sentinel on as a side effect of disabling analytics.
        if ($enable || $sentinelWasEnabled) {
            StartSentinel::run($server, restart: true);
        }

        return $restartProxy;
    }

    private function saveProxyConfiguration(Server $server, bool $enable): void
    {
        $previousEnabled = (bool) $server->settings->is_traffic_analytics_enabled;
        $previousProxy = $server->proxy->all();

        try {
            $configuration = GetProxyConfiguration::run($server);
            ProxyPortParser::fromConfiguration($configuration);

            // The configuration is built from the in-memory setting; it is persisted only after the save succeeds.
            $server->settings->is_traffic_analytics_enabled = $enable;
            $configuration = applyTrafficAnalyticsToProxyConfiguration($server, $configuration);
            SaveProxyConfiguration::run($server, $configuration);
        } catch (Throwable $exception) {
            $server->settings->is_traffic_analytics_enabled = $previousEnabled;
            $server->proxy = $previousProxy;
            $server->save();

            throw $exception;
        }
    }

    private function proxyIsStopped(Server $server): bool
    {
        return (bool) $server->proxy->get('force_stop')
            || in_array($server->proxy->get('status'), self::STOPPED_PROXY_STATUSES, true);
    }
}
