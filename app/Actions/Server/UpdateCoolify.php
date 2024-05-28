<?php

namespace App\Actions\Server;

use Lorisleiva\Actions\Concerns\AsAction;
use App\Models\InstanceSettings;
use App\Models\Server;
use Illuminate\Support\Facades\Log;

class UpdateCoolify
{
    use AsAction;
    public ?Server $server = null;
    public ?string $latestVersion = null;
    public ?string $currentVersion = null;

    public function handle()
    {
        try {
            $settings = InstanceSettings::get();
            ray('Running InstanceAutoUpdateJob');
            $this->server = Server::find(0);
            if (!$this->server) {
                return;
            }
            CleanupDocker::run($this->server, false);
            $this->latestVersion = get_latest_version_of_coolify();
            $this->currentVersion = config('version');
            if (!$settings->is_auto_update_enabled) {
                Log::info('Auto update is disabled');
                throw new \Exception('Auto update is disabled');
            }
            if ($this->latestVersion === $this->currentVersion) {
                Log::info('Already on latest version');
                throw new \Exception('Already on latest version');
            }
            if (version_compare($this->latestVersion, $this->currentVersion, '<')) {
                Log::info('Latest version is lower than current version?!');
                throw new \Exception('Latest version is lower than current version?!');
            }
            Log::info("Updating from {$this->currentVersion} -> {$this->latestVersion}");
            $this->update();
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
            instant_remote_process([
                "sleep 10"
            ], $this->server);
            return;
        }
        instant_remote_process([
            "curl -fsSL https://cdn.coollabs.io/coolify/upgrade.sh -o /data/coolify/source/upgrade.sh",
            "bash /data/coolify/source/upgrade.sh $this->latestVersion"
        ], $this->server);
        send_internal_notification("Instance updated from {$this->currentVersion} -> {$this->latestVersion}");
        return;
    }
}
