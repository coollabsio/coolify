<?php

namespace App\Http\Livewire;

use App\Models\Server;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Livewire\Component;

class CheckUpdate extends Component
{
    public $updateAvailable = false;
    public $latestVersion = '4.0.0-nightly.1';
    protected $currentVersion;
    protected $image = 'ghcr.io/coollabsio/coolify';

    public function checkUpdate()
    {
        $response = Http::get('https://get.coollabs.io/versions.json');
        $versions = $response->json();
        // $this->latestVersion = data_get($versions, 'coolify.main.version');
        $this->currentVersion = config('coolify.version');
        version_compare($this->currentVersion, $this->latestVersion, '<') ? $this->updateAvailable = true : $this->updateAvailable = false;
    }
    public function render()
    {
        return view('livewire.check-update');
    }
}
