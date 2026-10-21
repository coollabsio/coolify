<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CheckForUpdatesJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** A manual check runs in the web request, so it does not wait for CDN retries. */
    public int $cdnTries = 3;

    public function __construct(int $cdnTries = 3)
    {
        $this->cdnTries = $cdnTries;
    }

    public function handle(): void
    {
        try {
            if (isDev() || isCloud()) {
                return;
            }
            $versions = refresh_versions_file($this->cdnTries);
            if ($versions === null) {
                return;
            }

            CheckTraefikVersionJob::dispatch();

            $latestVersion = data_get($versions, 'coolify.v4.version');
            $newVersionAvailable = version_compare($latestVersion, config('constants.coolify.version'), '>');
            instanceSettings()->update(['new_version_available' => $newVersionAvailable]);
        } catch (\Throwable $e) {
            // Consider implementing a notification to administrators
        }
    }
}
