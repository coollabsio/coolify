<?php

namespace App\Livewire\Server;

use App\Jobs\DockerCleanupJob;
use App\Models\Server;
use Exception;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Throwable;

class Advanced extends Component
{
    public Server $server;

    public array $parameters = [];

    #[Validate(['string'])]
    public string $serverDiskUsageCheckFrequency = '0 23 * * *';

    #[Validate(['integer', 'min:1', 'max:99'])]
    public int $serverDiskUsageNotificationThreshold = 50;

    #[Validate(['string', 'required'])]
    public string $dockerCleanupFrequency = '*/10 * * * *';

    #[Validate(['integer', 'min:1', 'max:99'])]
    public int $dockerCleanupThreshold = 10;

    #[Validate('boolean')]
    public bool $forceDockerCleanup = false;

    #[Validate('boolean')]
    public bool $deleteUnusedVolumes = false;

    #[Validate('boolean')]
    public bool $deleteUnusedNetworks = false;

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
        } catch (Throwable) {
            return redirect()->route('server.show');
        }

        return null;
    }

    public function syncData(bool $toModel = false)
    {
        if ($toModel) {
            $this->validate();
            $this->server->settings->concurrent_builds = $this->concurrentBuilds;
            $this->server->settings->dynamic_timeout = $this->dynamicTimeout;
            $this->server->settings->force_docker_cleanup = $this->forceDockerCleanup;
            $this->server->settings->docker_cleanup_frequency = $this->dockerCleanupFrequency;
            $this->server->settings->docker_cleanup_threshold = $this->dockerCleanupThreshold;
            $this->server->settings->server_disk_usage_notification_threshold = $this->serverDiskUsageNotificationThreshold;
            $this->server->settings->delete_unused_volumes = $this->deleteUnusedVolumes;
            $this->server->settings->delete_unused_networks = $this->deleteUnusedNetworks;
            $this->server->settings->server_disk_usage_check_frequency = $this->serverDiskUsageCheckFrequency;
            $this->server->settings->save();
        } else {
            $this->concurrentBuilds = $this->server->settings->concurrent_builds;
            $this->dynamicTimeout = $this->server->settings->dynamic_timeout;
            $this->forceDockerCleanup = $this->server->settings->force_docker_cleanup;
            $this->dockerCleanupFrequency = $this->server->settings->docker_cleanup_frequency;
            $this->dockerCleanupThreshold = $this->server->settings->docker_cleanup_threshold;
            $this->serverDiskUsageNotificationThreshold = $this->server->settings->server_disk_usage_notification_threshold;
            $this->deleteUnusedVolumes = $this->server->settings->delete_unused_volumes;
            $this->deleteUnusedNetworks = $this->server->settings->delete_unused_networks;
            $this->serverDiskUsageCheckFrequency = $this->server->settings->server_disk_usage_check_frequency;
        }
    }

    public function instantSave()
    {
        try {
            $this->syncData(true);
            $this->dispatch('success', 'Server updated.');
        } catch (Throwable $e) {
            return handleError($e, $this);
        }

        return null;
    }

    public function manualCleanup()
    {
        try {
            DockerCleanupJob::dispatch($this->server, true);
            $this->dispatch('success', 'Manual cleanup job started. Depending on the amount of data, this might take a while.');
        } catch (Throwable $e) {
            return handleError($e, $this);
        }

        return null;
    }

    public function submit()
    {
        try {
            if (! validate_cron_expression($this->dockerCleanupFrequency)) {
                $this->dockerCleanupFrequency = $this->server->settings->getOriginal('docker_cleanup_frequency');
                throw new Exception('Invalid Cron / Human expression for Docker Cleanup Frequency.');
            }
            if (! validate_cron_expression($this->serverDiskUsageCheckFrequency)) {
                $this->serverDiskUsageCheckFrequency = $this->server->settings->getOriginal('server_disk_usage_check_frequency');
                throw new Exception('Invalid Cron / Human expression for Disk Usage Check Frequency.');
            }
            $this->syncData(true);
            $this->dispatch('success', 'Server updated.');
        } catch (Throwable $e) {
            return handleError($e, $this);
        }

        return null;
    }

    public function render()
    {
        return view('livewire.server.advanced');
    }
}
