<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CheckForUpdatesJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        try {
            if (isDev() || isCloud()) {
                return;
            }
            $settings = instanceSettings();
            $response = Http::retry(3, 1000)->get(config('constants.coolify.versions_url'));
            if ($response->successful()) {
                $versions = $response->json();

                $latest_version = data_get($versions, 'coolify.v4.version');
                $current_version = config('constants.coolify.version');

                // Read existing cached version
                $existingVersions = null;
                $existingCoolifyVersion = null;
                if (File::exists(base_path('versions.json'))) {
                    $existingVersions = json_decode(File::get(base_path('versions.json')), true);
                    $existingCoolifyVersion = data_get($existingVersions, 'coolify.v4.version');
                }

                // Determine the BEST version to use (CDN, cache, or current)
                $bestVersion = $latest_version;

                // Check if cache has newer version than CDN
                if ($existingCoolifyVersion && version_compare($existingCoolifyVersion, $bestVersion, '>')) {
                    Log::warning('CDN served older Coolify version than cache', [
                        'cdn_version' => $latest_version,
                        'cached_version' => $existingCoolifyVersion,
                        'current_version' => $current_version,
                    ]);
                    $bestVersion = $existingCoolifyVersion;
                }

                // CRITICAL: Never allow bestVersion to be older than currently running version
                if (version_compare($bestVersion, $current_version, '<')) {
                    Log::warning('Version downgrade prevented in CheckForUpdatesJob', [
                        'cdn_version' => $latest_version,
                        'cached_version' => $existingCoolifyVersion,
                        'current_version' => $current_version,
                        'attempted_best' => $bestVersion,
                        'using' => $current_version,
                    ]);
                    $bestVersion = $current_version;
                }

                // Use data_set() for safe mutation (fixes #3)
                data_set($versions, 'coolify.v4.version', $bestVersion);
                $latest_version = $bestVersion;

                // ALWAYS write versions.json (for Sentinel, Helper, Traefik updates)
                File::put(base_path('versions.json'), json_encode($versions, JSON_PRETTY_PRINT));

                // Invalidate cache to ensure fresh data is loaded
                invalidate_versions_cache();

                // Only mark new version available if Coolify version actually increased
                if (version_compare($latest_version, $current_version, '>')) {
                    // New version available
                    $settings->update(['new_version_available' => true]);
                } else {
                    $settings->update(['new_version_available' => false]);
                }
            }
        } catch (\Throwable $e) {
            // Consider implementing a notification to administrators
        }
    }
}
