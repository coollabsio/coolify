<?php

namespace App\Actions\Proxy;

use App\Models\Server;
use App\Services\ProxyDashboardCacheService;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;

class GetProxyConfiguration
{
    use AsAction;

    public function handle(Server $server, bool $forceRegenerate = false): string
    {
        $proxyType = $server->proxyType();
        if ($proxyType === 'NONE') {
            return 'OK';
        }

        $proxy_configuration = null;

        if (! $forceRegenerate) {
            // Primary source: database
            $proxy_configuration = $server->proxy->get('last_saved_proxy_configuration');

            // Backfill: existing servers may not have DB config yet — read from disk once
            if (empty(trim($proxy_configuration ?? ''))) {
                $proxy_configuration = $this->backfillFromDisk($server);
            }
        }

        // Generate default configuration as last resort
        if ($forceRegenerate || empty(trim($proxy_configuration ?? ''))) {
            $custom_commands = [];
            if (! empty(trim($proxy_configuration ?? ''))) {
                $custom_commands = extractCustomProxyCommands($server, $proxy_configuration);
            }

            Log::warning('Proxy configuration regenerated to defaults', [
                'server_id' => $server->id,
                'server_name' => $server->name,
                'reason' => $forceRegenerate ? 'force_regenerate' : 'config_not_found',
            ]);

            $proxy_configuration = str(generateDefaultProxyConfiguration($server, $custom_commands))->trim()->value();
        }

        if (empty($proxy_configuration)) {
            throw new \Exception('Could not get or generate proxy configuration');
        }

        ProxyDashboardCacheService::isTraefikDashboardAvailableFromConfiguration($server, $proxy_configuration);

        return $proxy_configuration;
    }

    /**
     * Backfill: read config from disk for servers that predate DB storage.
     * Stores the result in the database so future reads skip SSH entirely.
     */
    private function backfillFromDisk(Server $server): ?string
    {
        $proxy_path = $server->proxyPath();
        $result = instant_remote_process([
            "mkdir -p $proxy_path",
            "cat $proxy_path/docker-compose.yml 2>/dev/null",
        ], $server, false);

        if (! empty(trim($result ?? ''))) {
            $server->proxy->last_saved_proxy_configuration = $result;
            $server->save();

            Log::info('Proxy config backfilled to database from disk', [
                'server_id' => $server->id,
            ]);

            return $result;
        }

        return null;
    }
}
