<?php

namespace App\Actions\Server;

use App\Jobs\PullHelperImageJob;
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
        $settings = instanceSettings();
        $this->server = Server::find(0);
        if (! $this->server) {
            return;
        }
        CleanupDocker::dispatch($this->server)->onQueue('high');
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
        $settings->new_version_available = false;
        $settings->save();
    }

    private function update()
    {
        if (isDev()) {
            remote_process([
                'sleep 10',
            ], $this->server);

            return;
        }

        $all_servers = Server::all();
        $servers = $all_servers->where('settings.is_usable', true)->where('settings.is_reachable', true)->where('ip', '!=', '1.2.3.4');
        foreach ($servers as $server) {
            PullHelperImageJob::dispatch($server);
        }

        instant_remote_process(["docker pull -q ghcr.io/coollabsio/coolify:{$this->latestVersion}"], $this->server, false);

        remote_process([
            'curl -fsSL https://cdn.coollabs.io/coolify/upgrade.sh -o /data/coolify/source/upgrade.sh',
            "bash /data/coolify/source/upgrade.sh $this->latestVersion",
        ], $this->server);
    }
}
