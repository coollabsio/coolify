<?php

namespace App\Livewire\Project\Database;

use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Health extends Component
{
    use AuthorizesRequests;

    public $database;

    #[Validate(['boolean'])]
    public bool $healthCheckEnabled = true;

    #[Validate(['integer', 'min:1'])]
    public int $healthCheckInterval = 15;

    #[Validate(['integer', 'min:1'])]
    public int $healthCheckTimeout = 5;

    #[Validate(['integer', 'min:1'])]
    public int $healthCheckRetries = 5;

    #[Validate(['integer', 'min:0'])]
    public int $healthCheckStartPeriod = 5;

    public function mount(): void
    {
        $this->authorize('view', $this->database);
        $this->syncData();
    }

    public function syncData(bool $toModel = false): void
    {
        if ($toModel) {
            $this->validate();
            $this->database->health_check_enabled = $this->healthCheckEnabled;
            $this->database->health_check_interval = $this->healthCheckInterval;
            $this->database->health_check_timeout = $this->healthCheckTimeout;
            $this->database->health_check_retries = $this->healthCheckRetries;
            $this->database->health_check_start_period = $this->healthCheckStartPeriod;
            $this->database->save();
        } else {
            $this->healthCheckEnabled = $this->database->health_check_enabled;
            $this->healthCheckInterval = $this->database->health_check_interval;
            $this->healthCheckTimeout = $this->database->health_check_timeout;
            $this->healthCheckRetries = $this->database->health_check_retries;
            $this->healthCheckStartPeriod = $this->database->health_check_start_period;
        }
    }

    public function instantSave(): void
    {
        $this->submit();
    }

    public function submit(): void
    {
        $updateSuccessful = false;

        try {
            $this->authorize('update', $this->database);
            $this->syncData(true);
            $updateSuccessful = true;
            $this->dispatch('success', 'Health check updated. Restart the database to apply the changes.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }

        if (! $updateSuccessful) {
            return;
        }

        $this->markConfigurationChanged();
    }

    public function toggleHealthcheck(): void
    {
        $updateSuccessful = false;

        try {
            $this->authorize('update', $this->database);
            $this->healthCheckEnabled = ! $this->healthCheckEnabled;
            $this->syncData(true);
            $updateSuccessful = true;
            $this->dispatch('success', 'Health check '.($this->healthCheckEnabled ? 'enabled' : 'disabled').'. Restart the database to apply the changes.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }

        if (! $updateSuccessful) {
            return;
        }

        $this->markConfigurationChanged();
    }

    private function markConfigurationChanged(): void
    {
        if (is_null($this->database->config_hash)) {
            $this->database->isConfigurationChanged(true);

            return;
        }

        $this->dispatch('configurationChanged');
    }

    public function render(): View
    {
        return view('livewire.project.database.health');
    }
}
