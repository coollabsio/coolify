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
