<?php

namespace App\Livewire\Notifications;

use App\Models\SlackNotificationSettings;
use App\Models\Team;
use App\Notifications\Test;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Slack extends Component
{
    protected $listeners = ['refresh' => '$refresh'];

    #[Locked]
    public Team $team;

    #[Locked]
    public SlackNotificationSettings $settings;

    #[Validate(['boolean'])]
    public bool $slackEnabled = false;

    #[Validate(['url', 'nullable'])]
    public ?string $slackWebhookUrl = null;

    #[Validate(['boolean'])]
    public bool $deploymentSuccessSlackNotifications = false;

    #[Validate(['boolean'])]
    public bool $deploymentFailureSlackNotifications = true;

    #[Validate(['boolean'])]
    public bool $statusChangeSlackNotifications = false;

    #[Validate(['boolean'])]
    public bool $backupSuccessSlackNotifications = false;

    #[Validate(['boolean'])]
    public bool $backupFailureSlackNotifications = true;

    #[Validate(['boolean'])]
    public bool $scheduledTaskSuccessSlackNotifications = false;

    #[Validate(['boolean'])]
    public bool $scheduledTaskFailureSlackNotifications = true;

    #[Validate(['boolean'])]
    public bool $dockerCleanupSuccessSlackNotifications = false;

    #[Validate(['boolean'])]
    public bool $dockerCleanupFailureSlackNotifications = true;

    #[Validate(['boolean'])]
    public bool $serverDiskUsageSlackNotifications = true;

    #[Validate(['boolean'])]
    public bool $serverReachableSlackNotifications = false;

    #[Validate(['boolean'])]
    public bool $serverUnreachableSlackNotifications = true;

    public function mount()
    {
        try {
            $this->team = auth()->user()->currentTeam();
            $this->settings = $this->team->slackNotificationSettings;
            $this->syncData();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function syncData(bool $toModel = false)
    {
        if ($toModel) {
            $this->validate();
            $this->settings->slack_enabled = $this->slackEnabled;
            $this->settings->slack_webhook_url = $this->slackWebhookUrl;

            $this->settings->deployment_success_slack_notifications = $this->deploymentSuccessSlackNotifications;
            $this->settings->deployment_failure_slack_notifications = $this->deploymentFailureSlackNotifications;
            $this->settings->status_change_slack_notifications = $this->statusChangeSlackNotifications;
            $this->settings->backup_success_slack_notifications = $this->backupSuccessSlackNotifications;
            $this->settings->backup_failure_slack_notifications = $this->backupFailureSlackNotifications;
            $this->settings->scheduled_task_success_slack_notifications = $this->scheduledTaskSuccessSlackNotifications;
            $this->settings->scheduled_task_failure_slack_notifications = $this->scheduledTaskFailureSlackNotifications;
            $this->settings->docker_cleanup_success_slack_notifications = $this->dockerCleanupSuccessSlackNotifications;
            $this->settings->docker_cleanup_failure_slack_notifications = $this->dockerCleanupFailureSlackNotifications;
            $this->settings->server_disk_usage_slack_notifications = $this->serverDiskUsageSlackNotifications;
            $this->settings->server_reachable_slack_notifications = $this->serverReachableSlackNotifications;
            $this->settings->server_unreachable_slack_notifications = $this->serverUnreachableSlackNotifications;

            $this->settings->save();
            refreshSession();
        } else {
            $this->slackEnabled = $this->settings->slack_enabled;
            $this->slackWebhookUrl = $this->settings->slack_webhook_url;

            $this->deploymentSuccessSlackNotifications = $this->settings->deployment_success_slack_notifications;
            $this->deploymentFailureSlackNotifications = $this->settings->deployment_failure_slack_notifications;
            $this->statusChangeSlackNotifications = $this->settings->status_change_slack_notifications;
            $this->backupSuccessSlackNotifications = $this->settings->backup_success_slack_notifications;
            $this->backupFailureSlackNotifications = $this->settings->backup_failure_slack_notifications;
            $this->scheduledTaskSuccessSlackNotifications = $this->settings->scheduled_task_success_slack_notifications;
            $this->scheduledTaskFailureSlackNotifications = $this->settings->scheduled_task_failure_slack_notifications;
            $this->dockerCleanupSuccessSlackNotifications = $this->settings->docker_cleanup_success_slack_notifications;
            $this->dockerCleanupFailureSlackNotifications = $this->settings->docker_cleanup_failure_slack_notifications;
            $this->serverDiskUsageSlackNotifications = $this->settings->server_disk_usage_slack_notifications;
            $this->serverReachableSlackNotifications = $this->settings->server_reachable_slack_notifications;
            $this->serverUnreachableSlackNotifications = $this->settings->server_unreachable_slack_notifications;
        }
    }

    public function instantSaveSlackEnabled()
    {
        try {
            $this->validate([
                'slackWebhookUrl' => 'required',
            ], [
                'slackWebhookUrl.required' => 'Slack Webhook URL is required.',
            ]);
            $this->saveModel();
        } catch (\Throwable $e) {
            $this->slackEnabled = false;

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
            $this->team->notify(new Test(channel: 'slack'));
            $this->dispatch('success', 'Test notification sent.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.notifications.slack');
    }
}
