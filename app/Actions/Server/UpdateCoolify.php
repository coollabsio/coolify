<?php

namespace App\Actions\Server;

use App\Models\InstanceSettings;
use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

class UpdateCoolify
{
    use AsAction;

    public ?Server $server = null;

    public ?string $latestVersion = null;

    public ?string $currentVersion = null;

    public function handle($manual_update = false)
    {
        try {
            $settings = InstanceSettings::get();
            ray('Running InstanceAutoUpdateJob');
            $this->server = Server::find(0);
            if (! $this->server) {
                return;
            }
            CleanupDocker::dispatch($this->server, false)->onQueue('high');
            $this->latestVersion = get_latest_version_of_coolify();
            $this->currentVersion = config('version');
            if (! $manual_update) {
                if (! $settings->is_auto_update_enabled) {
                    return;
                }
                if ($this->latestVersion === $this->currentVersion) {
                    return;
                }
                if (version_compare($this->latestVersion, $this->currentVersion, '<')) {
                    return;
                }
            }
            $this->update();
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    private function update()
    {

        if (isDev()) {
            ray('Running in dev mode');
            remote_process([
                'sleep 10',
            ], $this->server);

            return;
        }
        $base_path = config('coolify.coolify_root_path');
        remote_process([
            "curl -fsSL https://cdn.coollabs.io/coolify/upgrade.sh -o {$base_path}/source/upgrade.sh",
            "bash {$base_path}/source/upgrade.sh $this->latestVersion",
        ], $this->server);

    }
}
