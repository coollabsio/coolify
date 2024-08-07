<?php

namespace App\Livewire;

use App\Actions\Server\UpdateCoolify;
use App\Models\InstanceSettings;
use Livewire\Component;

class Upgrade extends Component
{
    public bool $showProgress = false;

    public bool $updateInProgress = false;

    public bool $isUpgradeAvailable = false;

    public string $latestVersion = '';

    protected $listeners = ['updateAvailable' => 'checkUpdate'];

    public function checkUpdate()
    {
        try {
            $settings = InstanceSettings::get();
            $this->isUpgradeAvailable = $settings->new_version_available;
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }

    }

    public function upgrade()
    {
        try {
            if ($this->updateInProgress) {
                return;
            }
            $this->updateInProgress = true;
            UpdateCoolify::run(manual_update: true);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
