<?php

namespace App\Jobs;

use App\Enums\ActivityTypes;
use App\Models\InstanceSettings;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AutoUpdateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ShouldBeUnique;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        $instance_settings = InstanceSettings::get();
        if (!$instance_settings->is_auto_update_enabled) {
            Log::info('Auto update is disabled');
            dd('Auto update is disabled');
            $this->delete();
        }
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if (config('app.env') === 'local') {
            $latest_version = getLatestVersionOfCoolify();
            $current_version = config('version');
            if ($latest_version === $current_version) {
                dd('no update, versions match', $latest_version, $current_version);
                return;
            }
            if (version_compare($latest_version, $current_version, '<')) {
                dd('no update, latest version is lower than current version');
                return;
            }

            $server = Server::where('ip', 'coolify-testing-host')->first();
            if (!$server) {
                return;
            }
            instantRemoteProcess([
                "sleep 2"
            ], $server);
            remoteProcess([
                "sleep 10"
            ], $server, ActivityTypes::INLINE->value);
            dd('update done');
        } else {
            $latest_version = getLatestVersionOfCoolify();
            $current_version = config('version');
            if ($latest_version === $current_version) {
                return;
            }
            if (version_compare($latest_version, $current_version, '<')) {
                return;
            }

            $cdn = "https://coolify-cdn.b-cdn.net/files";
            $server = Server::where('ip', 'host.docker.internal')->first();
            if (!$server) {
                return;
            }

            instantRemoteProcess([
                "curl -fsSL $cdn/docker-compose.yml -o /data/coolify/source/docker-compose.yml",
                "curl -fsSL $cdn/docker-compose.prod.yml -o /data/coolify/source/docker-compose.prod.yml",
                "curl -fsSL $cdn/.env.production -o /data/coolify/source/.env.production",
                "curl -fsSL $cdn/upgrade.sh -o /data/coolify/source/upgrade.sh",
            ], $server);

            instantRemoteProcess([
                "docker compose -f /data/coolify/source/docker-compose.yml -f /data/coolify/source/docker-compose.prod.yml pull",
            ], $server);

            remoteProcess([
                "bash /data/coolify/source/upgrade.sh $latest_version"
            ], $server, ActivityTypes::INLINE->value);
        }
    }
}
