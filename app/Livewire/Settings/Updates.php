<?php

namespace App\Livewire\Settings;

use App\Jobs\CheckForUpdatesJob;
use App\Models\InstanceSettings;
use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Updates extends Component
{
    use AuthorizesRequests;

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
        if (! isInstanceAdmin()) {
            return redirect()->route('dashboard');
        }
        if (! isCloud()) {
            $this->server = Server::findOrFail(0);
        }

        $this->settings = instanceSettings();
        $this->auto_update_frequency = $this->settings->auto_update_frequency;
        $this->update_check_frequency = $this->settings->update_check_frequency;
        $this->is_auto_update_enabled = $this->settings->is_auto_update_enabled;
        $this->docker_registry_url = $this->settings->docker_registry_url ?: 'docker.io';
    }

    public function instantSave()
    {
        try {
            $this->authorize('update', $this->settings);
            if ($this->settings->is_auto_update_enabled === true) {
                $this->validate([
                    'auto_update_frequency' => ['required', 'string'],
                ]);
            }
            $validated = $this->validate([
                'docker_registry_url' => ['required', 'string', 'in:docker.io,ghcr.io'],
            ]);
            $this->settings->auto_update_frequency = $this->auto_update_frequency;
            $this->settings->update_check_frequency = $this->update_check_frequency;
            $this->settings->is_auto_update_enabled = $this->is_auto_update_enabled;
            $this->settings->docker_registry_url = $validated['docker_registry_url'];
            $this->syncRegistryUrlToEnv($validated['docker_registry_url']);
            $this->settings->save();
            $this->dispatch('success', 'Settings updated!');
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    protected function syncRegistryUrlToEnv(string $registryUrl): void
    {
        if (! $this->server) {
            return;
        }

        try {
            instant_remote_process([
                $this->registryEnvSyncCommand($registryUrl),
            ], $this->server);
        } catch (\Exception $e) {
            Log::warning('Failed to sync REGISTRY_URL to .env', [
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('Failed to sync REGISTRY_URL to .env. Settings were not saved.', previous: $e);
        }
    }

    private function registryEnvSyncCommand(string $registryUrl): string
    {
        $envFile = '/data/coolify/source/.env';
        $sedExpression = escapeshellarg("s|^REGISTRY_URL=.*|REGISTRY_URL={$registryUrl}|");
        $registryLine = escapeshellarg("REGISTRY_URL={$registryUrl}");

        return "if grep -q '^REGISTRY_URL=' {$envFile}; then sed -i {$sedExpression} {$envFile}; else printf '%s\\n' {$registryLine} >> {$envFile}; fi";
    }

    public function submit()
    {
        try {
            $this->authorize('update', $this->settings);
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
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    public function checkManually()
    {
        $this->authorize('update', $this->settings);
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
