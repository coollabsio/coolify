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

    #[Validate(['required', 'integer', 'min:1', 'max:99'])]
    public int|string $serverDiskUsageNotificationThreshold = 50;

    #[Validate(['required', 'integer', 'min:1', 'max:720'])]
    public int|string $serverDiskUsageNotificationIntervalHours = 24;

    #[Validate(['required', 'integer', 'min:1'])]
    public int|string $concurrentBuilds = 1;

    #[Validate(['required', 'integer', 'min:1'])]
    public int|string $dynamicTimeout = 1;

    #[Validate(['required', 'integer', 'min:1'])]
    public int|string $deploymentQueueLimit = 25;

    #[Validate(['required', 'integer', 'in:25,50,75,100'])]
    public int|string $backupCompressionCpuPercentage = 25;

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

    private function syncData(bool $toModel = false): void
    {
        if ($toModel) {
            $this->validate();
            $this->server->settings->concurrent_builds = $this->concurrentBuilds;
            $this->server->settings->dynamic_timeout = $this->dynamicTimeout;
            $this->server->settings->deployment_queue_limit = $this->deploymentQueueLimit;
            $this->server->settings->backup_compression_cpu_percentage = $this->backupCompressionCpuPercentage;
            $this->server->settings->server_disk_usage_notification_threshold = $this->serverDiskUsageNotificationThreshold;
            $this->server->settings->server_disk_usage_check_frequency = $this->serverDiskUsageCheckFrequency;
            $this->server->settings->server_disk_usage_notification_interval_hours = $this->serverDiskUsageNotificationIntervalHours;
            $changedFields = auditChangedFields($this->server->settings);
            $this->server->settings->save();
            $this->auditSettingsUpdate($changedFields);
        } else {
            $this->concurrentBuilds = $this->server->settings->concurrent_builds;
            $this->dynamicTimeout = $this->server->settings->dynamic_timeout;
            $this->deploymentQueueLimit = $this->server->settings->deployment_queue_limit;
            $this->backupCompressionCpuPercentage = $this->server->settings->backup_compression_cpu_percentage;
            $this->serverDiskUsageNotificationThreshold = $this->server->settings->server_disk_usage_notification_threshold;
            $this->serverDiskUsageCheckFrequency = $this->server->settings->server_disk_usage_check_frequency;
            $this->serverDiskUsageNotificationIntervalHours = $this->server->settings->server_disk_usage_notification_interval_hours;
        }
    }

    public function instantSave()
    {
        try {
            $this->authorize('update', $this->server);
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
            $this->authorize('update', $this->server);
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

    /**
     * @param  array<int, string>  $changedFields
     */
    private function auditSettingsUpdate(array $changedFields): void
    {
        if ($changedFields === []) {
            return;
        }

        auditLog('ui.server.settings_updated', [
            'team_id' => $this->server->team_id,
            'server_uuid' => $this->server->uuid,
            'server_name' => $this->server->name,
            'changed_fields' => $changedFields,
        ]);
    }
}
