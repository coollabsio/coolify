<?php

namespace App\Jobs;

use App\Models\InstanceSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\File;

class CheckForUpdatesJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        try {
            if (isDev() || isCloud()) {
                return;
            }
            $settings = InstanceSettings::get();
            $response = Http::retry(3, 1000)->get('https://cdn.coollabs.io/coolify/versions.json');
            if ($response->successful()) {
                $versions = $response->json();

                $latest_version = data_get($versions, 'coolify.v4.version');
                $current_version = config('version');

                if (version_compare($latest_version, $current_version, '>')) {
                    // New version available
                    $settings->update(['new_version_available' => true]);
                    File::put(base_path('versions.json'), json_encode($versions, JSON_PRETTY_PRINT));
                } else {
                    $settings->update(['new_version_available' => false]);
                }
            }
        } catch (\Throwable $e) {
            // Consider implementing a notification to administrators
        }
    }
}
