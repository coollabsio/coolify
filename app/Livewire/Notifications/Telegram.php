<?php

namespace App\Livewire\Notifications;

use App\Models\Team;
use App\Models\TelegramNotificationSettings;
use App\Notifications\Test;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Telegram extends Component
{
    public Team $team;

    public TelegramNotificationSettings $settings;

    #[Validate(['boolean'])]
    public bool $telegramEnabled = false;

    #[Validate(['nullable', 'string'])]
    public ?string $telegramToken = null;

    #[Validate(['nullable', 'string'])]
    public ?string $telegramChatId = null;

    #[Validate(['boolean'])]
    public bool $deploymentSuccessTelegramNotifications = false;

    #[Validate(['boolean'])]
    public bool $deploymentFailureTelegramNotifications = true;

    #[Validate(['boolean'])]
    public bool $statusChangeTelegramNotifications = false;

    #[Validate(['boolean'])]
    public bool $backupSuccessTelegramNotifications = false;

    #[Validate(['boolean'])]
    public bool $backupFailureTelegramNotifications = true;

    #[Validate(['boolean'])]
    public bool $scheduledTaskSuccessTelegramNotifications = false;

    #[Validate(['boolean'])]
    public bool $scheduledTaskFailureTelegramNotifications = true;

    #[Validate(['boolean'])]
    public bool $dockerCleanupSuccessTelegramNotifications = false;

    #[Validate(['boolean'])]
    public bool $dockerCleanupFailureTelegramNotifications = true;

    #[Validate(['boolean'])]
    public bool $serverDiskUsageTelegramNotifications = true;

    #[Validate(['boolean'])]
    public bool $serverReachableTelegramNotifications = false;

    #[Validate(['boolean'])]
    public bool $serverUnreachableTelegramNotifications = true;

    #[Validate(['nullable', 'string'])]
    public ?string $telegramNotificationsDeploymentSuccessTopicId = null;

    #[Validate(['nullable', 'string'])]
    public ?string $telegramNotificationsDeploymentFailureTopicId = null;

    #[Validate(['nullable', 'string'])]
    public ?string $telegramNotificationsStatusChangeTopicId = null;

    #[Validate(['nullable', 'string'])]
    public ?string $telegramNotificationsBackupSuccessTopicId = null;

    #[Validate(['nullable', 'string'])]
    public ?string $telegramNotificationsBackupFailureTopicId = null;

    #[Validate(['nullable', 'string'])]
    public ?string $telegramNotificationsScheduledTaskSuccessTopicId = null;

    #[Validate(['nullable', 'string'])]
    public ?string $telegramNotificationsScheduledTaskFailureTopicId = null;

    #[Validate(['nullable', 'string'])]
    public ?string $telegramNotificationsDockerCleanupSuccessTopicId = null;

    #[Validate(['nullable', 'string'])]
    public ?string $telegramNotificationsDockerCleanupFailureTopicId = null;

    #[Validate(['nullable', 'string'])]
    public ?string $telegramNotificationsServerDiskUsageTopicId = null;

    #[Validate(['nullable', 'string'])]
    public ?string $telegramNotificationsServerReachableTopicId = null;

    #[Validate(['nullable', 'string'])]
    public ?string $telegramNotificationsServerUnreachableTopicId = null;

    public function mount()
    {
        try {
            $this->team = auth()->user()->currentTeam();
            $this->settings = $this->team->telegramNotificationSettings;
            $this->syncData();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function syncData(bool $toModel = false)
    {
        if ($toModel) {
            $this->validate();
            $this->settings->telegram_enabled = $this->telegramEnabled;
            $this->settings->telegram_token = $this->telegramToken;
            $this->settings->telegram_chat_id = $this->telegramChatId;

            $this->settings->deployment_success_telegram_notifications = $this->deploymentSuccessTelegramNotifications;
            $this->settings->deployment_failure_telegram_notifications = $this->deploymentFailureTelegramNotifications;
            $this->settings->status_change_telegram_notifications = $this->statusChangeTelegramNotifications;
            $this->settings->backup_success_telegram_notifications = $this->backupSuccessTelegramNotifications;
            $this->settings->backup_failure_telegram_notifications = $this->backupFailureTelegramNotifications;
            $this->settings->scheduled_task_success_telegram_notifications = $this->scheduledTaskSuccessTelegramNotifications;
            $this->settings->scheduled_task_failure_telegram_notifications = $this->scheduledTaskFailureTelegramNotifications;
            $this->settings->docker_cleanup_success_telegram_notifications = $this->dockerCleanupSuccessTelegramNotifications;
            $this->settings->docker_cleanup_failure_telegram_notifications = $this->dockerCleanupFailureTelegramNotifications;
            $this->settings->server_disk_usage_telegram_notifications = $this->serverDiskUsageTelegramNotifications;
            $this->settings->server_reachable_telegram_notifications = $this->serverReachableTelegramNotifications;
            $this->settings->server_unreachable_telegram_notifications = $this->serverUnreachableTelegramNotifications;

            $this->settings->telegram_notifications_deployment_success_topic_id = $this->telegramNotificationsDeploymentSuccessTopicId;
            $this->settings->telegram_notifications_deployment_failure_topic_id = $this->telegramNotificationsDeploymentFailureTopicId;
            $this->settings->telegram_notifications_status_change_topic_id = $this->telegramNotificationsStatusChangeTopicId;
            $this->settings->telegram_notifications_backup_success_topic_id = $this->telegramNotificationsBackupSuccessTopicId;
            $this->settings->telegram_notifications_backup_failure_topic_id = $this->telegramNotificationsBackupFailureTopicId;
            $this->settings->telegram_notifications_scheduled_task_success_topic_id = $this->telegramNotificationsScheduledTaskSuccessTopicId;
            $this->settings->telegram_notifications_scheduled_task_failure_topic_id = $this->telegramNotificationsScheduledTaskFailureTopicId;
            $this->settings->telegram_notifications_docker_cleanup_success_topic_id = $this->telegramNotificationsDockerCleanupSuccessTopicId;
            $this->settings->telegram_notifications_docker_cleanup_failure_topic_id = $this->telegramNotificationsDockerCleanupFailureTopicId;
            $this->settings->telegram_notifications_server_disk_usage_topic_id = $this->telegramNotificationsServerDiskUsageTopicId;
            $this->settings->telegram_notifications_server_reachable_topic_id = $this->telegramNotificationsServerReachableTopicId;
            $this->settings->telegram_notifications_server_unreachable_topic_id = $this->telegramNotificationsServerUnreachableTopicId;

            $this->settings->save();
            refreshSession();
        } else {
            $this->telegramEnabled = $this->settings->telegram_enabled;
            $this->telegramToken = $this->settings->telegram_token;
            $this->telegramChatId = $this->settings->telegram_chat_id;

            $this->deploymentSuccessTelegramNotifications = $this->settings->deployment_success_telegram_notifications;
            $this->deploymentFailureTelegramNotifications = $this->settings->deployment_failure_telegram_notifications;
            $this->statusChangeTelegramNotifications = $this->settings->status_change_telegram_notifications;
            $this->backupSuccessTelegramNotifications = $this->settings->backup_success_telegram_notifications;
            $this->backupFailureTelegramNotifications = $this->settings->backup_failure_telegram_notifications;
            $this->scheduledTaskSuccessTelegramNotifications = $this->settings->scheduled_task_success_telegram_notifications;
            $this->scheduledTaskFailureTelegramNotifications = $this->settings->scheduled_task_failure_telegram_notifications;
            $this->dockerCleanupSuccessTelegramNotifications = $this->settings->docker_cleanup_success_telegram_notifications;
            $this->dockerCleanupFailureTelegramNotifications = $this->settings->docker_cleanup_failure_telegram_notifications;
            $this->serverDiskUsageTelegramNotifications = $this->settings->server_disk_usage_telegram_notifications;
            $this->serverReachableTelegramNotifications = $this->settings->server_reachable_telegram_notifications;
            $this->serverUnreachableTelegramNotifications = $this->settings->server_unreachable_telegram_notifications;

            $this->telegramNotificationsDeploymentSuccessTopicId = $this->settings->telegram_notifications_deployment_success_topic_id;
            $this->telegramNotificationsDeploymentFailureTopicId = $this->settings->telegram_notifications_deployment_failure_topic_id;
            $this->telegramNotificationsStatusChangeTopicId = $this->settings->telegram_notifications_status_change_topic_id;
            $this->telegramNotificationsBackupSuccessTopicId = $this->settings->telegram_notifications_backup_success_topic_id;
            $this->telegramNotificationsBackupFailureTopicId = $this->settings->telegram_notifications_backup_failure_topic_id;
            $this->telegramNotificationsScheduledTaskSuccessTopicId = $this->settings->telegram_notifications_scheduled_task_success_topic_id;
            $this->telegramNotificationsScheduledTaskFailureTopicId = $this->settings->telegram_notifications_scheduled_task_failure_topic_id;
            $this->telegramNotificationsDockerCleanupSuccessTopicId = $this->settings->telegram_notifications_docker_cleanup_success_topic_id;
            $this->telegramNotificationsDockerCleanupFailureTopicId = $this->settings->telegram_notifications_docker_cleanup_failure_topic_id;
            $this->telegramNotificationsServerDiskUsageTopicId = $this->settings->telegram_notifications_server_disk_usage_topic_id;
            $this->telegramNotificationsServerReachableTopicId = $this->settings->telegram_notifications_server_reachable_topic_id;
            $this->telegramNotificationsServerUnreachableTopicId = $this->settings->telegram_notifications_server_unreachable_topic_id;
        }
    }

    public function instantSave()
    {
        try {
            $this->syncData(true);
        } catch (\Throwable $e) {
            return handleError($e, $this);
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

    public function instantSaveTelegramEnabled()
    {
        try {
            $this->validate([
                'telegramToken' => 'required',
                'telegramChatId' => 'required',
            ], [
                'telegramToken.required' => 'Telegram Token is required.',
                'telegramChatId.required' => 'Telegram Chat ID is required.',
            ]);
            $this->saveModel();
        } catch (\Throwable $e) {
            $this->telegramEnabled = false;

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
            $this->team->notify(new Test(channel: 'telegram'));
            $this->dispatch('success', 'Test notification sent.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.notifications.telegram');
    }
}
