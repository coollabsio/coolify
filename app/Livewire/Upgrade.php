<?php

namespace App\Livewire;

use App\Actions\Server\UpdateCoolify;

use Livewire\Component;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;

class Upgrade extends Component
{
    use WithRateLimiting;
    public bool $showProgress = false;
    public bool $updateInProgress = false;
    public bool $isUpgradeAvailable = false;
    public string $latestVersion = '';

    public function checkUpdate()
    {
        $this->latestVersion = get_latest_version_of_coolify();
        $currentVersion = config('version');
        version_compare($currentVersion, $this->latestVersion, '<') ? $this->isUpgradeAvailable = true : $this->isUpgradeAvailable = false;
        if (isDev()) {
            $this->isUpgradeAvailable = true;
        }
    }

    public function upgrade()
    {
        try {
            if ($this->updateInProgress) {
                return;
            }
            $this->rateLimit(1, 60);
            $this->updateInProgress = true;
            UpdateCoolify::run(force: true, async: true);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
