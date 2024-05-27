<?php

namespace App\Actions\Server;

use Lorisleiva\Actions\Concerns\AsAction;
use App\Models\InstanceSettings;
use App\Models\Server;

class UpdateCoolify
{
    use AsAction;
    public ?Server $server = null;
    public ?string $latestVersion = null;
    public ?string $currentVersion = null;
    public bool $async = false;

    public function handle(bool $force = false, bool $async = false)
    {
        try {
            $this->async = $async;
            $settings = InstanceSettings::get();
            ray('Running InstanceAutoUpdateJob');
            $this->server = Server::find(0);
            if (!$this->server) {
                return;
            }
            CleanupDocker::run($this->server, false);
            $this->latestVersion = get_latest_version_of_coolify();
            $this->currentVersion = config('version');
            // if ($settings->next_channel) {
            //     ray('next channel enabled');
            //     $this->latestVersion = 'next';
            // }
            if ($force) {
                $this->update();
            } else {
                if (!$settings->is_auto_update_enabled) {
                    return 'Auto update is disabled';
                }
                if ($this->latestVersion === $this->currentVersion) {
                    return 'Already on latest version';
                }
                if (version_compare($this->latestVersion, $this->currentVersion, '<')) {
                    return 'Latest version is lower than current version?!';
                }
                $this->update();
            }
        } catch (\Throwable $e) {
            ray('InstanceAutoUpdateJob failed');
            ray($e->getMessage());
            send_internal_notification('InstanceAutoUpdateJob failed: ' . $e->getMessage());
            throw $e;
        }
    }

    private function update()
    {
        if (isDev()) {
            ray("Running update on local docker container. Updating to $this->latestVersion");
            if ($this->async) {
                ray('Running async update');
                remote_process([
                    "sleep 10"
                ], $this->server);
            } else {
                instant_remote_process([
                    "sleep 10"
                ], $this->server);
            }
            ray('Update done');
            return;
        } else {
            ray('Running update on production server');
            if ($this->async) {
                remote_process([
                    "curl -fsSL https://cdn.coollabs.io/coolify/upgrade.sh -o /data/coolify/source/upgrade.sh",
                    "bash /data/coolify/source/upgrade.sh $this->latestVersion"
                ], $this->server);
            } else {
                instant_remote_process([
                    "curl -fsSL https://cdn.coollabs.io/coolify/upgrade.sh -o /data/coolify/source/upgrade.sh",
                    "bash /data/coolify/source/upgrade.sh $this->latestVersion"
                ], $this->server);
            }
            send_internal_notification("Instance updated from {$this->currentVersion} -> {$this->latestVersion}");
            return;
        }
    }
}
