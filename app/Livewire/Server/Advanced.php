<?php

namespace App\Livewire\Server;

use App\Models\InstanceSettings;
use App\Models\Server;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Advanced extends Component
{
    public Server $server;

    public array $parameters = [];

    #[Validate(['string'])]
    public string $serverDiskUsageCheckFrequency = '0 23 * * *';

    #[Validate(['integer', 'min:1', 'max:99'])]
    public int $serverDiskUsageNotificationThreshold = 50;

    #[Validate(['integer', 'min:1'])]
    public int $concurrentBuilds = 1;

    #[Validate(['integer', 'min:1'])]
    public int $dynamicTimeout = 1;

    #[Validate(['boolean'])]
    public bool $isTerminalEnabled = false;

    public function mount(string $server_uuid)
    {
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
            $this->parameters = get_route_parameters();
            $this->syncData();

        } catch (\Throwable) {
            return redirect()->route('server.index');
        }
    }

    public function toggleTerminal($password)
    {
        try {
            // Check if user is admin or owner
            if (! auth()->user()->isAdmin()) {
                throw new \Exception('Only team administrators and owners can modify terminal access.');
            }

            // Verify password unless two-step confirmation is disabled
            if (! data_get(InstanceSettings::get(), 'disable_two_step_confirmation')) {
                if (! Hash::check($password, Auth::user()->password)) {
                    $this->addError('password', 'The provided password is incorrect.');

                    return;
                }
            }

            // Toggle the terminal setting
            $this->server->settings->is_terminal_enabled = ! $this->server->settings->is_terminal_enabled;
            $this->server->settings->save();

            // Update the local property
            $this->isTerminalEnabled = $this->server->settings->is_terminal_enabled;

            $status = $this->isTerminalEnabled ? 'enabled' : 'disabled';
            $this->dispatch('success', "Terminal access has been {$status}.");
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function syncData(bool $toModel = false)
    {
        if ($toModel) {
            $this->validate();
            $this->server->settings->concurrent_builds = $this->concurrentBuilds;
            $this->server->settings->dynamic_timeout = $this->dynamicTimeout;
            $this->server->settings->server_disk_usage_notification_threshold = $this->serverDiskUsageNotificationThreshold;
            $this->server->settings->server_disk_usage_check_frequency = $this->serverDiskUsageCheckFrequency;
            $this->server->settings->save();
        } else {
            $this->concurrentBuilds = $this->server->settings->concurrent_builds;
            $this->dynamicTimeout = $this->server->settings->dynamic_timeout;
            $this->serverDiskUsageNotificationThreshold = $this->server->settings->server_disk_usage_notification_threshold;
            $this->serverDiskUsageCheckFrequency = $this->server->settings->server_disk_usage_check_frequency;
            $this->isTerminalEnabled = $this->server->settings->is_terminal_enabled;
        }
    }

    public function instantSave()
    {
        try {
            $this->syncData(true);
            $this->dispatch('success', 'Server updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function submit()
    {
        try {
            if (! validate_cron_expression($this->serverDiskUsageCheckFrequency)) {
                $this->serverDiskUsageCheckFrequency = $this->server->settings->getOriginal('server_disk_usage_check_frequency');
                throw new \Exception('Invalid Cron / Human expression for Disk Usage Check Frequency.');
            }
            $this->syncData(true);
            $this->dispatch('success', 'Server updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.server.advanced');
    }
}
