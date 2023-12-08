<?php

namespace App\Livewire;

use App\Actions\Server\UpdateCoolify;
use App\Models\InstanceSettings;
use Livewire\Component;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;

class Upgrade extends Component
{
    use WithRateLimiting;
    public bool $showProgress = false;
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
        $settings = InstanceSettings::get();
        if ($settings->next_channel) {
            $this->isUpgradeAvailable = true;
            $this->latestVersion = 'next';
        }
    }

    public function upgrade()
    {
        try {
            $this->rateLimit(1, 30);
            if ($this->showProgress) {
                return;
            }
            $this->showProgress = true;
            UpdateCoolify::run(true);
            $this->dispatch('success', "Upgrading to {$this->latestVersion} version...");
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
