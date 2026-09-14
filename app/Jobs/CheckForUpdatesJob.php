<?php

namespace App\Jobs;

use App\Services\CoolifyVersionSelector;
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
            $response = Http::retry(3, 1000)
                ->connectTimeout(10)
                ->timeout(10)
                ->get(config('constants.coolify.versions_url'));
            if ($response->successful()) {
                $versions = $response->json();
                $current_version = config('constants.coolify.version');

                // Read existing cached version
                $existingVersions = null;
                if (File::exists(base_path('versions.json'))) {
                    $existingVersions = json_decode(File::get(base_path('versions.json')), true);
                }

                $versions = $this->preserveNewerCachedVersions($versions, $existingVersions, $current_version);

                // ALWAYS write versions.json (for Sentinel, Helper, Traefik updates)
                File::put(base_path('versions.json'), json_encode($versions, JSON_PRETTY_PRINT));

                // Invalidate cache to ensure fresh data is loaded
                invalidate_versions_cache();

                $targetVersion = CoolifyVersionSelector::forManual(
                    $versions,
                    $current_version,
                    $settings->update_channel ?: 'stable',
                );

                $settings->update([
                    'new_version_available' => version_compare($targetVersion, $current_version, '>'),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Failed to check for Coolify updates', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function preserveNewerCachedVersions(array $versions, ?array $existingVersions, string $currentVersion): array
    {
        foreach (['coolify.v4.version', 'coolify.rc.version'] as $path) {
            $cdnVersion = data_get($versions, $path);
            $cachedVersion = data_get($existingVersions, $path);

            if (is_string($cachedVersion) && (! is_string($cdnVersion) || version_compare($cachedVersion, $cdnVersion, '>'))) {
                Log::warning('CDN served older Coolify version than cache', [
                    'path' => $path,
                    'cdn_version' => $cdnVersion,
                    'cached_version' => $cachedVersion,
                    'current_version' => $currentVersion,
                ]);
            }
        }

        $stableVersion = data_get($versions, 'coolify.v4.version');
        if (! CoolifyVersionSelector::isReleaseCandidate($currentVersion)
            && is_string($stableVersion)
            && version_compare($stableVersion, $currentVersion, '<')) {
            Log::warning('Version downgrade prevented in CheckForUpdatesJob', [
                'cdn_version' => $stableVersion,
                'current_version' => $currentVersion,
                'using' => $currentVersion,
            ]);
        }

        return CoolifyVersionSelector::reconcileMetadata($versions, $existingVersions, $currentVersion);
    }
}
