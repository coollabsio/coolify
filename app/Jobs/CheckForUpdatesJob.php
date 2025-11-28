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

                // Detect CDN serving older Coolify version
                if ($existingCoolifyVersion && version_compare($latest_version, $existingCoolifyVersion, '<')) {
                    Log::warning('CDN served older Coolify version', [
                        'cdn_version' => $latest_version,
                        'cached_version' => $existingCoolifyVersion,
                        'current_version' => $current_version,
                    ]);

                    // Keep the NEWER Coolify version from cache, but update other components
                    $versions['coolify']['v4']['version'] = $existingCoolifyVersion;
                    $latest_version = $existingCoolifyVersion;
                }

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
