<?php

namespace App\Http\Livewire;

use App\Models\Server;
use Illuminate\Support\Facades\Http;
use Livewire\Component;

class CheckUpdate extends Component
{
    public $updateAvailable = false;
    public $latestVersion = 'latest';
    protected $currentVersion;
    protected $image = 'ghcr.io/coollabsio/coolify';

    protected function upgrade()
    {
        $cdn = "https://coolify-cdn.b-cdn.net/files";
        $server = Server::where('ip', 'host.docker.internal')->first();
        if (!$server) {
            return;
        }

        runRemoteCommandSync($server, [
            "curl -fsSL $cdn/docker-compose.yml -o /data/coolify/source/docker-compose.yml",
            "curl -fsSL $cdn/docker-compose.prod.yml -o /data/coolify/source/docker-compose.prod.yml",
            "curl -fsSL $cdn/.env.production -o /data/coolify/source/.env.production",
            "curl -fsSL $cdn/upgrade.sh -o /data/coolify/source/upgrade.sh",
            "nohup bash /data/coolify/source/upgrade.sh $this->latestVersion &"
        ]);
        $this->emit('updateInitiated');
    }
    public function forceUpgrade()
    {
        $this->checkUpdate();
        $this->upgrade();
    }
    public function checkUpdate()
    {
        $response = Http::get('https://get.coollabs.io/versions.json');
        $versions = $response->json();
        $this->latestVersion = data_get($versions, 'coolify.v4.version');
        $this->currentVersion = config('coolify.version');
        if ($this->latestVersion === 'latest') {
            $this->updateAvailable = true;
            return;
        }
        version_compare($this->currentVersion, $this->latestVersion, '<') ? $this->updateAvailable = true : $this->updateAvailable = false;
    }
}
