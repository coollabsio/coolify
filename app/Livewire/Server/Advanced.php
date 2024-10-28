<?php

namespace App\Livewire\Server;

use App\Jobs\DockerCleanupJob;
use App\Models\Server;
use Livewire\Component;

class Advanced extends Component
{
    public Server $server;

    protected $rules = [
        'server.settings.concurrent_builds' => 'required|integer|min:1',
        'server.settings.dynamic_timeout' => 'required|integer|min:1',
        'server.settings.force_docker_cleanup' => 'required|boolean',
        'server.settings.docker_cleanup_frequency' => 'required_if:server.settings.force_docker_cleanup,true|string',
        'server.settings.docker_cleanup_threshold' => 'required_if:server.settings.force_docker_cleanup,false|integer|min:1|max:100',
        'server.settings.server_disk_usage_notification_threshold' => 'required|integer|min:50|max:100',
        'server.settings.delete_unused_volumes' => 'boolean',
        'server.settings.delete_unused_networks' => 'boolean',
    ];

    protected $validationAttributes = [

        'server.settings.concurrent_builds' => 'Concurrent Builds',
        'server.settings.dynamic_timeout' => 'Dynamic Timeout',
        'server.settings.force_docker_cleanup' => 'Force Docker Cleanup',
        'server.settings.docker_cleanup_frequency' => 'Docker Cleanup Frequency',
        'server.settings.docker_cleanup_threshold' => 'Docker Cleanup Threshold',
        'server.settings.server_disk_usage_notification_threshold' => 'Server Disk Usage Notification Threshold',
        'server.settings.delete_unused_volumes' => 'Delete Unused Volumes',
        'server.settings.delete_unused_networks' => 'Delete Unused Networks',
    ];

    public function instantSave()
    {
        try {
            $this->validate();
            $this->server->settings->save();
            $this->dispatch('success', 'Server updated.');
            $this->dispatch('refreshServerShow');
        } catch (\Throwable $e) {
            $this->server->settings->refresh();

            return handleError($e, $this);
        }
    }

    public function manualCleanup()
    {
        try {
            DockerCleanupJob::dispatch($this->server, true);
            $this->dispatch('success', 'Manual cleanup job started. Depending on the amount of data, this might take a while.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function submit()
    {
        try {
            $frequency = $this->server->settings->docker_cleanup_frequency;
            if (empty($frequency) || ! validate_cron_expression($frequency)) {
                $this->server->settings->docker_cleanup_frequency = '*/10 * * * *';
                throw new \Exception('Invalid Cron / Human expression for Docker Cleanup Frequency. Resetting to default 10 minutes.');
            }
            $this->server->settings->save();
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
