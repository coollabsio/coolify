<?php

namespace App\Http\Livewire;

use App\Actions\Server\UpdateCoolify;
use Masmerise\Toaster\Toaster;
use Livewire\Component;

class Upgrade extends Component
{
    public bool $showProgress = false;
    public bool $isUpgradeAvailable = false;

    public function checkUpdate()
    {
        $latestVersion = get_latest_version_of_coolify();
        $currentVersion = config('version');
        version_compare($currentVersion, $latestVersion, '<') ? $this->isUpgradeAvailable = true : $this->isUpgradeAvailable = false;
        if (config('app.env') === 'local') {
            $this->isUpgradeAvailable = true;
        }
    }
    public function upgrade()
    {
        try {
            $this->showProgress = true;
            resolve(UpdateCoolify::class)(true);
            Toaster::success('Update started.');
        } catch (\Exception $e) {
            return general_error_handler(err: $e, that: $this);
        }
    }
}
