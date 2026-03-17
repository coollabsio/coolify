<?php

namespace App\Livewire\Settings;

use App\Jobs\CheckForUpdatesJob;
use App\Models\InstanceSettings;
use App\Models\Server;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Updates extends Component
{
    public InstanceSettings $settings;

    public ?Server $server = null;

    #[Validate('string')]
    public string $auto_update_frequency;

    #[Validate('string|required')]
    public string $update_check_frequency;

    #[Validate('boolean')]
    public bool $is_auto_update_enabled;

    #[Validate('required|string|in:docker.io,ghcr.io')]
    public string $docker_registry_url;

    public function mount()
    {
        if (! isCloud()) {
            $this->server = Server::findOrFail(0);
        }

        $this->settings = instanceSettings();
        $this->auto_update_frequency = $this->settings->auto_update_frequency;
        $this->update_check_frequency = $this->settings->update_check_frequency;
        $this->is_auto_update_enabled = $this->settings->is_auto_update_enabled;
        $this->docker_registry_url = $this->settings->docker_registry_url ?: config('constants.coolify.registry_url');
    }

    public function instantSave()
    {
        try {
            if ($this->settings->is_auto_update_enabled === true) {
                $this->validate([
                    'auto_update_frequency' => ['required', 'string'],
                ]);
            }
            $this->settings->auto_update_frequency = $this->auto_update_frequency;
            $this->settings->update_check_frequency = $this->update_check_frequency;
            $this->settings->is_auto_update_enabled = $this->is_auto_update_enabled;
            $this->settings->docker_registry_url = $this->docker_registry_url;
            $this->settings->save();
            $this->syncRegistryUrlToEnv();
            $this->dispatch('success', 'Settings updated!');
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    private function syncRegistryUrlToEnv(): void
    {
        if (! $this->server) {
            return;
        }

        try {
            $registryUrl = $this->docker_registry_url;
            instant_remote_process([
                "sed -i 's|^REGISTRY_URL=.*|REGISTRY_URL={$registryUrl}|' /data/coolify/source/.env",
            ], $this->server);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Failed to sync REGISTRY_URL to .env', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function submit()
    {
        try {
            $this->resetErrorBag();
            $this->validate();

            if ($this->is_auto_update_enabled && ! validate_cron_expression($this->auto_update_frequency)) {
                $this->dispatch('error', 'Invalid Cron / Human expression for Auto Update Frequency.');
                if (empty($this->auto_update_frequency)) {
                    $this->auto_update_frequency = '0 0 * * *';
                }

                return;
            }

            if (! validate_cron_expression($this->update_check_frequency)) {
                $this->dispatch('error', 'Invalid Cron / Human expression for Update Check Frequency.');
                if (empty($this->update_check_frequency)) {
                    $this->update_check_frequency = '0 * * * *';
                }

                return;
            }

            $this->instantSave();
            if ($this->server) {
                $this->server->setupDynamicProxyConfiguration();
            }
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    public function checkManually()
    {
        CheckForUpdatesJob::dispatchSync();
        $this->dispatch('updateAvailable');
        $settings = instanceSettings();
        if ($settings->new_version_available) {
            $this->dispatch('success', 'New version available!');
        } else {
            $this->dispatch('success', 'No new version available.');
        }
    }

    public function render()
    {
        return view('livewire.settings.updates');
    }
}
