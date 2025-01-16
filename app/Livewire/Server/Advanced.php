<?php

namespace App\Livewire\Server;

use App\Models\Server;
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
