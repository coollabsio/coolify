<?php

namespace App\Livewire;

use App\Actions\Server\UpdateCoolify;
use App\Models\InstanceSettings;
use Illuminate\Support\Facades\Http;
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
            $response = Http::retry(3, 1000)->get('https://cdn.coollabs.io/coolify/versions.json');
            if ($response->successful()) {
                $versions = $response->json();
                $this->latestVersion = data_get($versions, 'coolify.v4.version');
            }
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
