<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

/**
 * Get cached versions data from versions.json.
 *
 * This function provides a centralized, cached access point for all
 * version data in the application. Data is cached in Redis for 1 hour
 * and shared across all servers in the cluster.
 *
 * @return array|null The versions data array, or null if file doesn't exist
 */
function get_versions_data(): ?array
{
    return Cache::remember('coolify:versions:all', 3600, function () {
        $versionsPath = base_path('versions.json');

        if (! File::exists($versionsPath)) {
            return null;
        }

        return json_decode(File::get($versionsPath), true);
    });
}

/**
 * Get Traefik versions from cached data.
 *
 * @return array|null Array of Traefik versions (e.g., ['v3.5' => '3.5.6'])
 */
function get_traefik_versions(): ?array
{
    $versions = get_versions_data();

    if (! $versions) {
        return null;
    }

    $traefikVersions = data_get($versions, 'traefik');

    return is_array($traefikVersions) ? $traefikVersions : null;
}

/**
 * Invalidate the versions cache.
 * Call this after updating versions.json to ensure fresh data is loaded.
 */
function invalidate_versions_cache(): void
{
    Cache::forget('coolify:versions:all');
}
