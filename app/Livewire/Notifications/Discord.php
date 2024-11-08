<?php

namespace App\Livewire\Notifications;

use App\Models\Team;
use App\Notifications\Test;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Discord extends Component
{
    public Team $team;

    #[Validate(['boolean'])]
    public bool $discordEnabled = false;

    #[Validate(['url', 'nullable'])]
    public ?string $discordWebhookUrl = null;

    #[Validate(['boolean'])]
    public bool $discordNotificationsTest = false;

    #[Validate(['boolean'])]
    public bool $discordNotificationsDeployments = false;

    #[Validate(['boolean'])]
    public bool $discordNotificationsStatusChanges = false;

    #[Validate(['boolean'])]
    public bool $discordNotificationsDatabaseBackups = false;

    #[Validate(['boolean'])]
    public bool $discordNotificationsScheduledTasks = false;

    #[Validate(['boolean'])]
    public bool $discordNotificationsServerDiskUsage = false;

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
            $this->team->discord_enabled = $this->discordEnabled;
            $this->team->discord_webhook_url = $this->discordWebhookUrl;
            $this->team->discord_notifications_test = $this->discordNotificationsTest;
            $this->team->discord_notifications_deployments = $this->discordNotificationsDeployments;
            $this->team->discord_notifications_status_changes = $this->discordNotificationsStatusChanges;
            $this->team->discord_notifications_database_backups = $this->discordNotificationsDatabaseBackups;
            $this->team->discord_notifications_scheduled_tasks = $this->discordNotificationsScheduledTasks;
            $this->team->discord_notifications_server_disk_usage = $this->discordNotificationsServerDiskUsage;
            $this->team->save();
            refreshSession();
        } else {
            $this->discordEnabled = $this->team->discord_enabled;
            $this->discordWebhookUrl = $this->team->discord_webhook_url;
            $this->discordNotificationsTest = $this->team->discord_notifications_test;
            $this->discordNotificationsDeployments = $this->team->discord_notifications_deployments;
            $this->discordNotificationsStatusChanges = $this->team->discord_notifications_status_changes;
            $this->discordNotificationsDatabaseBackups = $this->team->discord_notifications_database_backups;
            $this->discordNotificationsScheduledTasks = $this->team->discord_notifications_scheduled_tasks;
            $this->discordNotificationsServerDiskUsage = $this->team->discord_notifications_server_disk_usage;
        }
    }

    public function instantSaveDiscordEnabled()
    {
        try {
            $this->validate([
                'discordWebhookUrl' => 'required',
            ], [
                'discordWebhookUrl.required' => 'Discord Webhook URL is required.',
            ]);
            $this->saveModel();
        } catch (\Throwable $e) {
            $this->discordEnabled = false;

            return handleError($e, $this);
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
        $this->syncData(true);
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
        return view('livewire.notifications.discord');
    }
}
