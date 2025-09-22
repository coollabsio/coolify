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

    public Server $server;

    #[Validate('string')]
    public string $auto_update_frequency;

    #[Validate('string|required')]
    public string $update_check_frequency;

    #[Validate('boolean')]
    public bool $is_auto_update_enabled;

    public function mount()
    {
        $this->server = Server::findOrFail(0);

        $this->settings = instanceSettings();
        $this->auto_update_frequency = $this->settings->auto_update_frequency;
        $this->update_check_frequency = $this->settings->update_check_frequency;
        $this->is_auto_update_enabled = $this->settings->is_auto_update_enabled;
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
            $this->settings->save();
            $this->dispatch('success', 'Settings updated!');
        } catch (\Exception $e) {
            return handleError($e, $this);
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
            $this->server->setupDynamicProxyConfiguration();
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
