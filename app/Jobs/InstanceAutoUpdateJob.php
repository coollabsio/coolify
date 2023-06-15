<?php

namespace App\Jobs;

use App\Models\InstanceSettings;
use App\Models\Server;
use Exception;
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

    public $timeout = 120;

    public Server $server;
    public string $latest_version;
    public string $current_version;


    public function __construct(private bool $force = false)
    {
    }
    private function update()
    {
        if (config('app.env') === 'local') {
            ray('Running update on local docker container');
            instant_remote_process([
                "sleep 10"
            ], $this->server);
            ray('Update done');
            return;
        } else {
            ray('Running update on production server');
            instant_remote_process([
                "curl -fsSL https://cdn.coollabs.io/coolify/upgrade.sh -o /data/coolify/source/upgrade.sh",
                "bash /data/coolify/source/upgrade.sh $this->latest_version"
            ], $this->server);
            return;
        }
    }
    public function handle(): void
    {
        try {
            ray('Running InstanceAutoUpdateJob');
            $localhost_name = 'localhost';
            if (config('app.env') === 'local') {
                $localhost_name = 'testing-local-docker-container';
            }
            $this->server = Server::where('name', $localhost_name)->firstOrFail();
            $this->latest_version = get_latest_version_of_coolify();
            $this->current_version = config('version');
            ray('latest version:' . $this->latest_version . " current version: " .  $this->current_version . ' force: ' . $this->force);
            if ($this->force) {
                $this->update();
            } else {
                $instance_settings = InstanceSettings::get();
                ray($instance_settings);
                if (!$instance_settings->is_auto_update_enabled) {
                    throw new \Exception('Auto update is disabled');
                }
                if ($this->latest_version === $this->current_version) {
                    throw new \Exception('Already on latest version');
                }
                if (version_compare($this->latest_version, $this->current_version, '<')) {
                    throw new \Exception('Latest version is lower than current version?!');
                }
                $this->update();
            }
            return;
        } catch (\Exception $e) {
            ray('InstanceAutoUpdateJob failed');
            ray($e->getMessage());
            $this->fail($e->getMessage());
            return;
        }
    }
    public function failed(Exception $exception)
    {
        return;
    }
}
