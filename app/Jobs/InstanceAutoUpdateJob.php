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

    public $tries = 1;
    public $timeout = 120;
    public function uniqueId(): int
    {
        return 1;
    }
    public function __construct(private bool $force = false)
    {
    }

    public function handle(): void
    {
        try {
            $localhost_name = 'localhost';
            if (config('app.env') === 'local') {
                $localhost_name = 'testing-local-docker-container';
            }
            $server = Server::where('name', $localhost_name)->firstOrFail();
            $latest_version = get_latest_version_of_coolify();
            $current_version = config('version');

            if (config('app.env') === 'local') {
                instant_remote_process([
                    "sleep 10"
                ], $server);
                return;
            } else {
                if (!$this->force) {
                    $instance_settings = InstanceSettings::get();
                    if (!$instance_settings->is_auto_update_enabled) {
                        $this->fail('Auto update is disabled');
                    }
                    if ($latest_version === $current_version) {
                        $this->fail("Already on latest version");
                    }
                    if (version_compare($latest_version, $current_version, '<')) {
                        $this->fail("Latest version is lower than current version?!");
                    }
                }
                instant_remote_process([
                    "curl -fsSL https://coolify-cdn.b-cdn.net/files/upgrade.sh -o /data/coolify/source/upgrade.sh",
                    "bash /data/coolify/source/upgrade.sh $latest_version"
                ], $server);
                return;
            }
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            $this->fail($e->getMessage());
        }
    }
}
