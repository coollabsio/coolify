<?php

namespace App\Livewire\Notifications;

use App\Models\Team;
use App\Models\TelegramNotificationSettings;
use App\Notifications\Test;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Telegram extends Component
{
    protected $listeners = ['refresh' => '$refresh'];

    #[Locked]
    public Team $team;

    #[Locked]
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
    public ?string $telegramNotificationsDeploymentSuccessThreadId = null;

    #[Validate(['nullable', 'string'])]
    public ?string $telegramNotificationsDeploymentFailureThreadId = null;

    #[Validate(['nullable', 'string'])]
    public ?string $telegramNotificationsStatusChangeThreadId = null;

    #[Validate(['nullable', 'string'])]
    public ?string $telegramNotificationsBackupSuccessThreadId = null;

    #[Validate(['nullable', 'string'])]
    public ?string $telegramNotificationsBackupFailureThreadId = null;

    #[Validate(['nullable', 'string'])]
    public ?string $telegramNotificationsScheduledTaskSuccessThreadId = null;

    #[Validate(['nullable', 'string'])]
    public ?string $telegramNotificationsScheduledTaskFailureThreadId = null;

    #[Validate(['nullable', 'string'])]
    public ?string $telegramNotificationsDockerCleanupSuccessThreadId = null;

    #[Validate(['nullable', 'string'])]
    public ?string $telegramNotificationsDockerCleanupFailureThreadId = null;

    #[Validate(['nullable', 'string'])]
    public ?string $telegramNotificationsServerDiskUsageThreadId = null;

    #[Validate(['nullable', 'string'])]
    public ?string $telegramNotificationsServerReachableThreadId = null;

    #[Validate(['nullable', 'string'])]
    public ?string $telegramNotificationsServerUnreachableThreadId = null;

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

            $this->settings->telegram_notifications_deployment_success_thread_id = $this->telegramNotificationsDeploymentSuccessThreadId;
            $this->settings->telegram_notifications_deployment_failure_thread_id = $this->telegramNotificationsDeploymentFailureThreadId;
            $this->settings->telegram_notifications_status_change_thread_id = $this->telegramNotificationsStatusChangeThreadId;
            $this->settings->telegram_notifications_backup_success_thread_id = $this->telegramNotificationsBackupSuccessThreadId;
            $this->settings->telegram_notifications_backup_failure_thread_id = $this->telegramNotificationsBackupFailureThreadId;
            $this->settings->telegram_notifications_scheduled_task_success_thread_id = $this->telegramNotificationsScheduledTaskSuccessThreadId;
            $this->settings->telegram_notifications_scheduled_task_failure_thread_id = $this->telegramNotificationsScheduledTaskFailureThreadId;
            $this->settings->telegram_notifications_docker_cleanup_success_thread_id = $this->telegramNotificationsDockerCleanupSuccessThreadId;
            $this->settings->telegram_notifications_docker_cleanup_failure_thread_id = $this->telegramNotificationsDockerCleanupFailureThreadId;
            $this->settings->telegram_notifications_server_disk_usage_thread_id = $this->telegramNotificationsServerDiskUsageThreadId;
            $this->settings->telegram_notifications_server_reachable_thread_id = $this->telegramNotificationsServerReachableThreadId;
            $this->settings->telegram_notifications_server_unreachable_thread_id = $this->telegramNotificationsServerUnreachableThreadId;

            $this->settings->save();
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

            $this->telegramNotificationsDeploymentSuccessThreadId = $this->settings->telegram_notifications_deployment_success_thread_id;
            $this->telegramNotificationsDeploymentFailureThreadId = $this->settings->telegram_notifications_deployment_failure_thread_id;
            $this->telegramNotificationsStatusChangeThreadId = $this->settings->telegram_notifications_status_change_thread_id;
            $this->telegramNotificationsBackupSuccessThreadId = $this->settings->telegram_notifications_backup_success_thread_id;
            $this->telegramNotificationsBackupFailureThreadId = $this->settings->telegram_notifications_backup_failure_thread_id;
            $this->telegramNotificationsScheduledTaskSuccessThreadId = $this->settings->telegram_notifications_scheduled_task_success_thread_id;
            $this->telegramNotificationsScheduledTaskFailureThreadId = $this->settings->telegram_notifications_scheduled_task_failure_thread_id;
            $this->telegramNotificationsDockerCleanupSuccessThreadId = $this->settings->telegram_notifications_docker_cleanup_success_thread_id;
            $this->telegramNotificationsDockerCleanupFailureThreadId = $this->settings->telegram_notifications_docker_cleanup_failure_thread_id;
            $this->telegramNotificationsServerDiskUsageThreadId = $this->settings->telegram_notifications_server_disk_usage_thread_id;
            $this->telegramNotificationsServerReachableThreadId = $this->settings->telegram_notifications_server_reachable_thread_id;
            $this->telegramNotificationsServerUnreachableThreadId = $this->settings->telegram_notifications_server_unreachable_thread_id;
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
        } finally {
            $this->dispatch('refresh');
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
