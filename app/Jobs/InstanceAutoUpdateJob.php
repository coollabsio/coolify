<?php

namespace App\Jobs;

use App\Models\InstanceSettings;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Log;

class InstanceAutoUpdateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private string $latest_version;
    private string $current_version;
    private Server $server;

    public function __construct(private bool $force = false)
    {
        if (config('app.env') === 'local') {
            $server_name = 'testing-local-docker-container';
        } else {
            $server_name = 'localhost';
        }
        $server = Server::where('name', $server_name)->first();
        if (is_null($server)) {
            throw new \Exception("Server not found");
        }
        $this->server = $server;
        $this->latest_version = get_latest_version_of_coolify();
        $this->current_version = config('version');

        if (!$this->force) {
            $instance_settings = InstanceSettings::get();
            if (!$instance_settings->is_auto_update_enabled) {
                return $this->delete();
            }
            try {
                $this->check_if_update_available();
            } catch (\Exception $e) {
                Log::error($e->getMessage());
                return $this->delete();
            }
        }
    }
    private function check_if_update_available()
    {
        if ($this->latest_version === $this->current_version) {
            throw new \Exception("Already on latest version");
        }
        if (version_compare($this->latest_version, $this->current_version, '<')) {
            throw new \Exception("Already on latest version");
        }
    }
    public function handle(): void
    {
        try {
            if (config('app.env') === 'local') {
                instant_remote_process([
                    "sleep 10"
                ], $this->server);
            } else {
                instant_remote_process([
                    "curl -fsSL https://coolify-cdn.b-cdn.net/files/upgrade.sh -o /data/coolify/source/upgrade.sh",
                    "bash /data/coolify/source/upgrade.sh $this->latest_version"
                ], $this->server);
            }
        } catch (\Exception $e) {
            Log::error($e->getMessage());
        }
    }
}
