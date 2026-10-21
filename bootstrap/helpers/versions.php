<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

/**
 * Fetch versions.json from the CDN and write it to the local file of this node.
 * The Coolify version never goes below the cached or the running version.
 * When the CDN fails, the local file stays as it is.
 *
 * @return array|null The written versions data, or null if the CDN fetch failed
 */
function refresh_versions_file(): ?array
{
    $response = Http::timeout(10)->retry(3, 1000, throw: false)->get(config('constants.coolify.versions_url'));
    $versions = $response->json();
    if ($response->failed() || ! is_array($versions)) {
        Log::warning('Could not fetch versions.json from the CDN, using the local file', ['status' => $response->status()]);

        return null;
    }

    $latestVersion = data_get($versions, 'coolify.v4.version');
    $currentVersion = config('constants.coolify.version');

    $existingCoolifyVersion = null;
    if (File::exists(base_path('versions.json'))) {
        $existingVersions = json_decode(File::get(base_path('versions.json')), true);
        $existingCoolifyVersion = data_get($existingVersions, 'coolify.v4.version');
    }

    $bestVersion = $latestVersion;

    if ($existingCoolifyVersion && version_compare($existingCoolifyVersion, $bestVersion, '>')) {
        Log::warning('CDN served older Coolify version than cache', [
            'cdn_version' => $latestVersion,
            'cached_version' => $existingCoolifyVersion,
            'current_version' => $currentVersion,
        ]);
        $bestVersion = $existingCoolifyVersion;
    }

    if (version_compare($bestVersion, $currentVersion, '<')) {
        Log::warning('Version downgrade prevented in CheckForUpdatesJob', [
            'cdn_version' => $latestVersion,
            'cached_version' => $existingCoolifyVersion,
            'current_version' => $currentVersion,
            'attempted_best' => $bestVersion,
            'using' => $currentVersion,
        ]);
        $bestVersion = $currentVersion;
    }

    data_set($versions, 'coolify.v4.version', $bestVersion);

    File::put(base_path('versions.json'), json_encode($versions, JSON_PRETTY_PRINT));
    invalidate_versions_cache();

    return $versions;
}
