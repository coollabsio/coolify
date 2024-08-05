<?php

namespace App\Jobs;

use App\Models\InstanceSettings;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class PullCoolifyImageJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        try {
            if (isDev() || isCloud()) {
                return;
            }
            $settings = InstanceSettings::get();
            $server = Server::findOrFail(0);
            $response = Http::retry(3, 1000)->get('https://cdn.coollabs.io/coolify/versions.json');
            if ($response->successful()) {
                $versions = $response->json();
                File::put(base_path('versions.json'), json_encode($versions, JSON_PRETTY_PRINT));
            }
            $latest_version = get_latest_version_of_coolify();
            instant_remote_process(["docker pull -q ghcr.io/coollabsio/coolify:{$latest_version}"], $server, false);

            $current_version = config('version');
            if (! $settings->is_auto_update_enabled) {
                return;
            }
            if ($latest_version === $current_version) {
                return;
            }
            if (version_compare($latest_version, $current_version, '<')) {
                return;
            }
        } catch (\Throwable $e) {
            throw $e;
        }
    }
}