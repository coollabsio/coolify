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
use Log;

class InstanceAutoUpdateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private string $latest_version;
    private string $current_version;
    private Server $server;
    private string $server_name = 'localhost';

    public function __construct(private bool $force = false)
    {
        if (config('app.env') === 'local') {
            $this->server_name = 'coolify-testing-host';
        }

        $instance_settings = InstanceSettings::get();
        $this->server = Server::where('name', $this->server_name)->firstOrFail();
        Log::info($this->server);
        Log::info('Force: ' . $this->force);

        $this->latest_version = get_latest_version_of_coolify();
        Log::info('Latest version: ' . $this->latest_version);
        $this->current_version = config('version');
        Log::info('Current version: ' . $this->current_version);

        if (!$this->force) {
            if (!$instance_settings->is_auto_update_enabled || !$this->server) {
                return $this->delete();
            }
            Log::info('Checking if update available');
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
                    "sleep 2"
                ], $this->server);
                remote_process([
                    "sleep 10"
                ], $this->server);
            } else {
                Log::info('Downloading upgrade script');
                instant_remote_process([
                    "curl -fsSL https://coolify-cdn.b-cdn.net/files/upgrade.sh -o /data/coolify/source/upgrade.sh",
                ], $this->server);
                Log::info('Running upgrade script');
                remote_process([
                    "bash /data/coolify/source/upgrade.sh $this->latest_version"
                ], $this->server);
            }
        } catch (\Exception $e) {
            Log::error($e->getMessage());
        }
    }
}
