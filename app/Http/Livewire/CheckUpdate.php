<?php

namespace App\Http\Livewire;

use App\Models\Server;
use Illuminate\Support\Facades\Http;
use Livewire\Component;

class CheckUpdate extends Component
{
    public $updateAvailable = false;
    public $latestVersion = '4.0.0-nightly.1';
    protected $currentVersion;
    protected $image = 'ghcr.io/coollabsio/coolify';

    protected function upgrade()
    {
        $server = Server::where('ip', 'host.docker.internal')->first();
        if (!$server) {
            return;
        }
        runRemoteCommandSync($server, ['curl -fsSL https://raw.githubusercontent.com/coollabsio/coolify/v4/scripts/upgrade.sh -o /data/coolify/source/upgrade.sh']);
        runRemoteCommandSync($server, ['bash /data/coolify/source/upgrade.sh']);
    }
    public function forceUpgrade()
    {
        $this->upgrade();
    }
    public function checkUpdate()
    {
        $response = Http::get('https://get.coollabs.io/versions.json');
        $versions = $response->json();
        // $this->latestVersion = data_get($versions, 'coolify.main.version');
        $this->currentVersion = config('coolify.version');
        version_compare($this->currentVersion, $this->latestVersion, '<') ? $this->updateAvailable = true : $this->updateAvailable = false;
    }
}
