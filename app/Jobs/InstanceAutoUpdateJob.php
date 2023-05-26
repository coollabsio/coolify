<?php

namespace App\Jobs;

use App\Models\InstanceSettings;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Log;

class InstanceAutoUpdateJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private string $latest_version;
    private string $current_version;
    private Server $server;
    private string $localhost_name = 'localhost';
    public $tries = 1;
    public $timeout = 120;
    public function uniqueId(): int
    {
        return 1;
    }
    public function __construct(private bool $force = false)
    {
        try {
            if (config('app.env') === 'local') {
                $localhost_name = 'testing-local-docker-container';
            }
            $this->server = Server::where('name', $localhost_name)->firstOrFail();
            $this->latest_version = get_latest_version_of_coolify();
            $this->current_version = config('version');

            if (!$this->force) {
                $instance_settings = InstanceSettings::get();
                if (!$instance_settings->is_auto_update_enabled) {
                    return $this->fail('Auto update is disabled');
                }
                $this->check_if_update_available();
            }
        } catch (\Exception $e) {
            $this->fail($e->getMessage());
        }
    }
    private function check_if_update_available()
    {
        if ($this->latest_version === $this->current_version) {
            $this->fail("Already on latest version");
        }
        if (version_compare($this->latest_version, $this->current_version, '<')) {
            $this->fail("Latest version is lower than current version?!");
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
            $this->fail($e->getMessage());
        }
    }
}
