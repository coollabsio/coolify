<?php

namespace App\Livewire\Notifications;

use App\Models\Team;
use App\Notifications\Test;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Telegram extends Component
{
    public Team $team;

    #[Validate(['boolean'])]
    public bool $telegramEnabled = false;

    #[Validate(['nullable', 'string'])]
    public ?string $telegramToken = null;

    #[Validate(['nullable', 'string'])]
    public ?string $telegramChatId = null;

    #[Validate(['boolean'])]
    public bool $telegramNotificationsTest = false;

    #[Validate(['boolean'])]
    public bool $telegramNotificationsDeployments = false;

    #[Validate(['boolean'])]
    public bool $telegramNotificationsStatusChanges = false;

    #[Validate(['boolean'])]
    public bool $telegramNotificationsDatabaseBackups = false;

    #[Validate(['boolean'])]
    public bool $telegramNotificationsScheduledTasks = false;

    #[Validate(['nullable', 'string'])]
    public ?string $telegramNotificationsTestMessageThreadId = null;

    #[Validate(['nullable', 'string'])]
    public ?string $telegramNotificationsDeploymentsMessageThreadId = null;

    #[Validate(['nullable', 'string'])]
    public ?string $telegramNotificationsStatusChangesMessageThreadId = null;

    #[Validate(['nullable', 'string'])]
    public ?string $telegramNotificationsDatabaseBackupsMessageThreadId = null;

    #[Validate(['nullable', 'string'])]
    public ?string $telegramNotificationsScheduledTasksThreadId = null;

    #[Validate(['boolean'])]
    public bool $telegramNotificationsServerDiskUsage = false;

    public function mount()
    {
        try {
            $this->team = auth()->user()->currentTeam();
            $this->syncData();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function syncData(bool $toModel = false)
    {
        if ($toModel) {
            $this->validate();
            $this->team->telegram_enabled = $this->telegramEnabled;
            $this->team->telegram_token = $this->telegramToken;
            $this->team->telegram_chat_id = $this->telegramChatId;
            $this->team->telegram_notifications_test = $this->telegramNotificationsTest;
            $this->team->telegram_notifications_deployments = $this->telegramNotificationsDeployments;
            $this->team->telegram_notifications_status_changes = $this->telegramNotificationsStatusChanges;
            $this->team->telegram_notifications_database_backups = $this->telegramNotificationsDatabaseBackups;
            $this->team->telegram_notifications_scheduled_tasks = $this->telegramNotificationsScheduledTasks;
            $this->team->telegram_notifications_test_message_thread_id = $this->telegramNotificationsTestMessageThreadId;
            $this->team->telegram_notifications_deployments_message_thread_id = $this->telegramNotificationsDeploymentsMessageThreadId;
            $this->team->telegram_notifications_status_changes_message_thread_id = $this->telegramNotificationsStatusChangesMessageThreadId;
            $this->team->telegram_notifications_database_backups_message_thread_id = $this->telegramNotificationsDatabaseBackupsMessageThreadId;
            $this->team->telegram_notifications_scheduled_tasks_thread_id = $this->telegramNotificationsScheduledTasksThreadId;
            $this->team->telegram_notifications_server_disk_usage = $this->telegramNotificationsServerDiskUsage;
            try {
                $this->saveModel();
            } catch (\Throwable $e) {
                return handleError($e, $this);
            }
        } else {
            $this->telegramEnabled = $this->team->telegram_enabled;
            $this->telegramToken = $this->team->telegram_token;
            $this->telegramChatId = $this->team->telegram_chat_id;
            $this->telegramNotificationsTest = $this->team->telegram_notifications_test;
            $this->telegramNotificationsDeployments = $this->team->telegram_notifications_deployments;
            $this->telegramNotificationsStatusChanges = $this->team->telegram_notifications_status_changes;
            $this->telegramNotificationsDatabaseBackups = $this->team->telegram_notifications_database_backups;
            $this->telegramNotificationsScheduledTasks = $this->team->telegram_notifications_scheduled_tasks;
            $this->telegramNotificationsTestMessageThreadId = $this->team->telegram_notifications_test_message_thread_id;
            $this->telegramNotificationsDeploymentsMessageThreadId = $this->team->telegram_notifications_deployments_message_thread_id;
            $this->telegramNotificationsStatusChangesMessageThreadId = $this->team->telegram_notifications_status_changes_message_thread_id;
            $this->telegramNotificationsDatabaseBackupsMessageThreadId = $this->team->telegram_notifications_database_backups_message_thread_id;
            $this->telegramNotificationsScheduledTasksThreadId = $this->team->telegram_notifications_scheduled_tasks_thread_id;
            $this->telegramNotificationsServerDiskUsage = $this->team->telegram_notifications_server_disk_usage;
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

    public function saveModel()
    {
        $this->team->save();
        refreshSession();
        $this->dispatch('success', 'Settings saved.');
    }

    public function sendTestNotification()
    {
        try {
            $this->team->notify(new Test);
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
