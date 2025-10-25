<?php

namespace App\Actions\Proxy;

use App\Models\Server;
use App\Services\ProxyDashboardCacheService;
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

        $proxy_path = $server->proxyPath();
        $proxy_configuration = null;

        // If not forcing regeneration, try to read existing configuration
        if (! $forceRegenerate) {
            $payload = [
                "mkdir -p $proxy_path",
                "cat $proxy_path/docker-compose.yml 2>/dev/null",
            ];
            $proxy_configuration = instant_remote_process($payload, $server, false);
        }

        // Generate default configuration if:
        // 1. Force regenerate is requested
        // 2. Configuration file doesn't exist or is empty
        if ($forceRegenerate || empty(trim($proxy_configuration ?? ''))) {
            // Extract custom commands from existing config before regenerating
            $custom_commands = [];
            if (! empty(trim($proxy_configuration ?? ''))) {
                $custom_commands = extractCustomProxyCommands($server, $proxy_configuration);
            }

            $proxy_configuration = str(generateDefaultProxyConfiguration($server, $custom_commands))->trim()->value();
        }

        if (empty($proxy_configuration)) {
            throw new \Exception('Could not get or generate proxy configuration');
        }

        ProxyDashboardCacheService::isTraefikDashboardAvailableFromConfiguration($server, $proxy_configuration);

        return $proxy_configuration;
    }
}
