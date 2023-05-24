<?php

namespace App\Jobs;

use App\Enums\ActivityTypes;
use App\Models\InstanceSettings;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class InstanceAutoUpdate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        $instance_settings = InstanceSettings::get();
        if (!$instance_settings->is_auto_update_enabled) {
            $this->delete();
        }
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if (config('app.env') === 'local') {
            $latest_version = get_latest_version_of_coolify();
            $current_version = config('version');
            if ($latest_version === $current_version) {
                return;
            }
            if (version_compare($latest_version, $current_version, '<')) {
                return;
            }

            $server = Server::where('ip', 'coolify-testing-host')->first();
            if (!$server) {
                return;
            }
            instant_remote_process([
                "sleep 2"
            ], $server);
            remote_process([
                "sleep 10"
            ], $server, ActivityTypes::INLINE->value);
        } else {
            $latest_version = get_latest_version_of_coolify();
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

            instant_remote_process([
                "curl -fsSL $cdn/docker-compose.yml -o /data/coolify/source/docker-compose.yml",
                "curl -fsSL $cdn/docker-compose.prod.yml -o /data/coolify/source/docker-compose.prod.yml",
                "curl -fsSL $cdn/.env.production -o /data/coolify/source/.env.production",
                "curl -fsSL $cdn/upgrade.sh -o /data/coolify/source/upgrade.sh",
            ], $server);

            instant_remote_process([
                "docker compose -f /data/coolify/source/docker-compose.yml -f /data/coolify/source/docker-compose.prod.yml pull",
            ], $server);

            remote_process([
                "bash /data/coolify/source/upgrade.sh $latest_version"
            ], $server, ActivityTypes::INLINE->value);
        }
    }
}
