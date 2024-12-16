<?php

namespace App\Livewire\Notifications;

use App\Models\PushoverNotificationSettings;
use App\Models\Team;
use App\Notifications\Test;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Pushover extends Component
{
    protected $listeners = ['refresh' => '$refresh'];

    #[Locked]
    public Team $team;

    #[Locked]
    public PushoverNotificationSettings $settings;

    #[Validate(['boolean'])]
    public bool $pushoverEnabled = false;

    #[Validate(['nullable', 'string'])]
    public ?string $pushoverUserKey = null;

    #[Validate(['nullable', 'string'])]
    public ?string $pushoverApiToken = null;

    #[Validate(['boolean'])]
    public bool $deploymentSuccessPushoverNotifications = false;

    #[Validate(['boolean'])]
    public bool $deploymentFailurePushoverNotifications = true;

    #[Validate(['boolean'])]
    public bool $statusChangePushoverNotifications = false;

    #[Validate(['boolean'])]
    public bool $backupSuccessPushoverNotifications = false;

    #[Validate(['boolean'])]
    public bool $backupFailurePushoverNotifications = true;

    #[Validate(['boolean'])]
    public bool $scheduledTaskSuccessPushoverNotifications = false;

    #[Validate(['boolean'])]
    public bool $scheduledTaskFailurePushoverNotifications = true;

    #[Validate(['boolean'])]
    public bool $dockerCleanupSuccessPushoverNotifications = false;

    #[Validate(['boolean'])]
    public bool $dockerCleanupFailurePushoverNotifications = true;

    #[Validate(['boolean'])]
    public bool $serverDiskUsagePushoverNotifications = true;

    #[Validate(['boolean'])]
    public bool $serverReachablePushoverNotifications = false;

    #[Validate(['boolean'])]
    public bool $serverUnreachablePushoverNotifications = true;

    public function mount()
    {
        try {
            $this->team = auth()->user()->currentTeam();
            $this->settings = $this->team->pushoverNotificationSettings;
            $this->syncData();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function syncData(bool $toModel = false)
    {
        if ($toModel) {
            $this->validate();
            $this->settings->pushover_enabled = $this->pushoverEnabled;
            $this->settings->pushover_user_key = $this->pushoverUserKey;
            $this->settings->pushover_api_token = $this->pushoverApiToken;

            $this->settings->deployment_success_pushover_notifications = $this->deploymentSuccessPushoverNotifications;
            $this->settings->deployment_failure_pushover_notifications = $this->deploymentFailurePushoverNotifications;
            $this->settings->status_change_pushover_notifications = $this->statusChangePushoverNotifications;
            $this->settings->backup_success_pushover_notifications = $this->backupSuccessPushoverNotifications;
            $this->settings->backup_failure_pushover_notifications = $this->backupFailurePushoverNotifications;
            $this->settings->scheduled_task_success_pushover_notifications = $this->scheduledTaskSuccessPushoverNotifications;
            $this->settings->scheduled_task_failure_pushover_notifications = $this->scheduledTaskFailurePushoverNotifications;
            $this->settings->docker_cleanup_success_pushover_notifications = $this->dockerCleanupSuccessPushoverNotifications;
            $this->settings->docker_cleanup_failure_pushover_notifications = $this->dockerCleanupFailurePushoverNotifications;
            $this->settings->server_disk_usage_pushover_notifications = $this->serverDiskUsagePushoverNotifications;
            $this->settings->server_reachable_pushover_notifications = $this->serverReachablePushoverNotifications;
            $this->settings->server_unreachable_pushover_notifications = $this->serverUnreachablePushoverNotifications;

            $this->settings->save();
            refreshSession();
        } else {
            $this->pushoverEnabled = $this->settings->pushover_enabled;
            $this->pushoverUserKey = $this->settings->pushover_user_key;
            $this->pushoverApiToken = $this->settings->pushover_api_token;

            $this->deploymentSuccessPushoverNotifications = $this->settings->deployment_success_pushover_notifications;
            $this->deploymentFailurePushoverNotifications = $this->settings->deployment_failure_pushover_notifications;
            $this->statusChangePushoverNotifications = $this->settings->status_change_pushover_notifications;
            $this->backupSuccessPushoverNotifications = $this->settings->backup_success_pushover_notifications;
            $this->backupFailurePushoverNotifications = $this->settings->backup_failure_pushover_notifications;
            $this->scheduledTaskSuccessPushoverNotifications = $this->settings->scheduled_task_success_pushover_notifications;
            $this->scheduledTaskFailurePushoverNotifications = $this->settings->scheduled_task_failure_pushover_notifications;
            $this->dockerCleanupSuccessPushoverNotifications = $this->settings->docker_cleanup_success_pushover_notifications;
            $this->dockerCleanupFailurePushoverNotifications = $this->settings->docker_cleanup_failure_pushover_notifications;
            $this->serverDiskUsagePushoverNotifications = $this->settings->server_disk_usage_pushover_notifications;
            $this->serverReachablePushoverNotifications = $this->settings->server_reachable_pushover_notifications;
            $this->serverUnreachablePushoverNotifications = $this->settings->server_unreachable_pushover_notifications;
        }
    }

    public function instantSavePushoverEnabled()
    {
        try {
            $this->validate([
                'pushoverUserKey' => 'required',
                'pushoverApiToken' => 'required',
            ], [
                'pushoverUserKey.required' => 'Pushover User Key is required.',
                'pushoverApiToken.required' => 'Pushover API Token is required.',
            ]);
            $this->saveModel();
        } catch (\Throwable $e) {
            $this->pushoverEnabled = false;

            return handleError($e, $this);
        } finally {
            $this->dispatch('refresh');
        }
    }

    public function instantSave()
    {
        try {
            $this->syncData(true);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        } finally {
            $this->dispatch('refresh');
        }
    }

    public function submit()
    {
        try {
            $this->resetErrorBag();
            $this->syncData(true);
            $this->saveModel();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function saveModel()
    {
        $this->syncData(true);
        refreshSession();
        $this->dispatch('success', 'Settings saved.');
    }

    public function sendTestNotification()
    {
        try {
            $this->team->notify(new Test(channel: 'pushover'));
            $this->dispatch('success', 'Test notification sent.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.notifications.pushover');
    }
}
