<?php

namespace App\Http\Livewire;

use Livewire\Component;

class CheckUpdate extends Component
{
    public $updateAvailable = false;
    public $latestVersion = 'latest';
    protected $currentVersion;
    protected $image = 'ghcr.io/coollabsio/coolify';

    public function checkUpdate()
    {
        $this->latestVersion = getLatestVersionOfCoolify();
        $this->currentVersion = config('version');
        if ($this->latestVersion === 'latest') {
            $this->updateAvailable = true;
            return;
        }
        version_compare($this->currentVersion, $this->latestVersion, '<') ? $this->updateAvailable = true : $this->updateAvailable = false;
    }
}
