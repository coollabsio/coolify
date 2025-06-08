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
     * Check if Traefik dashboard is available (from cache or compute)
     */
    public static function isTraefikDashboardAvailable(Server $server): bool
    {
        $cacheKey = static::getCacheKey($server);

        // Try to get from cache first
        $cachedValue = Cache::get($cacheKey);

        if ($cachedValue !== null) {
            return $cachedValue;
        }

        // If not in cache, compute the value
        try {
            $proxy_settings = \App\Actions\Proxy\CheckConfiguration::run($server);
            $dashboardAvailable = str($proxy_settings)->contains('--api.dashboard=true') &&
                                str($proxy_settings)->contains('--api.insecure=true');

            // Cache the result (cache indefinitely until proxy restart)
            Cache::forever($cacheKey, $dashboardAvailable);

            return $dashboardAvailable;
        } catch (\Throwable $e) {
            // If there's an error checking configuration, default to false and don't cache
            return false;
        }
    }

    /**
     * Clear Traefik dashboard cache for a server
     */
    public static function clearCache(Server $server): void
    {
        $cacheKey = static::getCacheKey($server);
        Cache::forget($cacheKey);
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
