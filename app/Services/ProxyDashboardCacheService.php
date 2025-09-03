<?php

namespace App\Services;

use App\Models\Server;
use Illuminate\Support\Facades\Cache;

class ProxyDashboardCacheService
{
    /**
     * Get Redis cache key for Traefik dashboard availability
     */
    public static function getCacheKey(Server $server): string
    {
        return "server:{$server->id}:traefik:dashboard_available";
    }

    /**
     * Check if Traefik dashboard is available from configuration
     */
    public static function isTraefikDashboardAvailableFromConfiguration(Server $server, string $proxy_configuration): void
    {
        $cacheKey = static::getCacheKey($server);
        $dashboardAvailable = str($proxy_configuration)->contains('--api.dashboard=true') &&
        str($proxy_configuration)->contains('--api.insecure=true');
        Cache::forever($cacheKey, $dashboardAvailable);
    }

    /**
     * Check if Traefik dashboard is available (from cache or compute)
     */
    public static function isTraefikDashboardAvailableFromCache(Server $server): bool
    {
        $cacheKey = static::getCacheKey($server);

        return Cache::get($cacheKey) ?? false;
    }

    /**
     * Clear Traefik dashboard cache for a server
     */
    public static function clearCache(Server $server): void
    {
        Cache::forget(static::getCacheKey($server));
    }

    /**
     * Clear Traefik dashboard cache for multiple servers
     */
    public static function clearCacheForServers(array $serverIds): void
    {
        foreach ($serverIds as $serverId) {
            $cacheKey = "server:{$serverId}:traefik:dashboard_available";
            Cache::forget($cacheKey);
        }
    }
}
