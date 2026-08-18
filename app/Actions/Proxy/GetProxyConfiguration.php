<?php

namespace App\Actions\Proxy;

use App\Enums\ProxyTypes;
use App\Models\Server;
use App\Services\ProxyDashboardCacheService;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Yaml\Yaml;

class GetProxyConfiguration
{
    use AsAction;

    public const MAX_CONFIGURATION_SIZE_BYTES = 5 * 1024 * 1024;

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

            // Validate stored config matches current proxy type
            if (! empty(trim($proxy_configuration ?? ''))) {
                if (! $this->configMatchesProxyType($proxyType, $proxy_configuration)) {
                    Log::warning('Stored proxy config does not match current proxy type, will regenerate', [
                        'server_id' => $server->id,
                        'proxy_type' => $proxyType,
                    ]);
                    $proxy_configuration = null;
                }
            }

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
     * Check that the stored docker-compose YAML contains the expected service
     * for the server's current proxy type. Returns false if the config belongs
     * to a different proxy type (e.g. Traefik config on a CADDY server).
     */
    private function configMatchesProxyType(string $proxyType, string $configuration): bool
    {
        try {
            $yaml = Yaml::parse($configuration);
            $services = data_get($yaml, 'services', []);

            return match ($proxyType) {
                ProxyTypes::TRAEFIK->value => isset($services['traefik']),
                ProxyTypes::CADDY->value => isset($services['caddy']),
                ProxyTypes::NGINX->value => isset($services['nginx']),
                default => true,
            };
        } catch (\Throwable $e) {
            // If YAML is unparseable, don't block — let the existing flow handle it
            return true;
        }
    }

    /**
     * Backfill: read config from disk for servers that predate DB storage.
     * Stores the result in the database so future reads skip SSH entirely.
     */
    private function backfillFromDisk(Server $server): ?string
    {
        $proxy_path = $server->proxyPath();
        $configurationPath = escapeshellarg("$proxy_path/docker-compose.yml");
        $readLimit = self::MAX_CONFIGURATION_SIZE_BYTES + 1;
        $result = instant_remote_process([
            "mkdir -p $proxy_path",
            "if [ ! -f {$configurationPath} ]; then exit 0; elif [ \"$(wc -c < {$configurationPath})\" -gt ".self::MAX_CONFIGURATION_SIZE_BYTES." ]; then echo '__COOLIFY_PROXY_CONFIG_TOO_LARGE__'; else head -c {$readLimit} {$configurationPath}; fi",
        ], $server, false);

        if ($result === '__COOLIFY_PROXY_CONFIG_TOO_LARGE__' || strlen($result ?? '') > self::MAX_CONFIGURATION_SIZE_BYTES) {
            throw new \RuntimeException('Proxy configuration exceeds the 5 MiB size limit.');
        }

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
