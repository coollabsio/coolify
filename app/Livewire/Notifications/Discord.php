<?php

namespace App\Livewire\Notifications;

use App\Models\DiscordNotificationSettings;
use App\Models\Team;
use App\Notifications\Test;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Discord extends Component
{
    public Team $team;

    public DiscordNotificationSettings $settings;

    #[Validate(['boolean'])]
    public bool $discordEnabled = false;

    #[Validate(['url', 'nullable'])]
    public ?string $discordWebhookUrl = null;

    #[Validate(['boolean'])]
    public bool $deploymentSuccessDiscordNotifications = false;

    #[Validate(['boolean'])]
    public bool $deploymentFailureDiscordNotifications = true;

    #[Validate(['boolean'])]
    public bool $statusChangeDiscordNotifications = false;

    #[Validate(['boolean'])]
    public bool $backupSuccessDiscordNotifications = false;

    #[Validate(['boolean'])]
    public bool $backupFailureDiscordNotifications = true;

    #[Validate(['boolean'])]
    public bool $scheduledTaskSuccessDiscordNotifications = false;

    #[Validate(['boolean'])]
    public bool $scheduledTaskFailureDiscordNotifications = true;

    #[Validate(['boolean'])]
    public bool $dockerCleanupSuccessDiscordNotifications = false;

    #[Validate(['boolean'])]
    public bool $dockerCleanupFailureDiscordNotifications = true;

    #[Validate(['boolean'])]
    public bool $serverDiskUsageDiscordNotifications = true;

    #[Validate(['boolean'])]
    public bool $serverReachableDiscordNotifications = false;

    #[Validate(['boolean'])]
    public bool $serverUnreachableDiscordNotifications = true;

    public function mount()
    {
        try {
            $this->team = auth()->user()->currentTeam();
            $this->settings = $this->team->discordNotificationSettings;
            $this->syncData();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function syncData(bool $toModel = false)
    {
        if ($toModel) {
            $this->validate();
            $this->settings->discord_enabled = $this->discordEnabled;
            $this->settings->discord_webhook_url = $this->discordWebhookUrl;

            $this->settings->deployment_success_discord_notifications = $this->deploymentSuccessDiscordNotifications;
            $this->settings->deployment_failure_discord_notifications = $this->deploymentFailureDiscordNotifications;
            $this->settings->status_change_discord_notifications = $this->statusChangeDiscordNotifications;
            $this->settings->backup_success_discord_notifications = $this->backupSuccessDiscordNotifications;
            $this->settings->backup_failure_discord_notifications = $this->backupFailureDiscordNotifications;
            $this->settings->scheduled_task_success_discord_notifications = $this->scheduledTaskSuccessDiscordNotifications;
            $this->settings->scheduled_task_failure_discord_notifications = $this->scheduledTaskFailureDiscordNotifications;
            $this->settings->docker_cleanup_success_discord_notifications = $this->dockerCleanupSuccessDiscordNotifications;
            $this->settings->docker_cleanup_failure_discord_notifications = $this->dockerCleanupFailureDiscordNotifications;
            $this->settings->server_disk_usage_discord_notifications = $this->serverDiskUsageDiscordNotifications;
            $this->settings->server_reachable_discord_notifications = $this->serverReachableDiscordNotifications;
            $this->settings->server_unreachable_discord_notifications = $this->serverUnreachableDiscordNotifications;

            $this->settings->save();
            refreshSession();
        } else {
            $this->discordEnabled = $this->settings->discord_enabled;
            $this->discordWebhookUrl = $this->settings->discord_webhook_url;

            $this->deploymentSuccessDiscordNotifications = $this->settings->deployment_success_discord_notifications;
            $this->deploymentFailureDiscordNotifications = $this->settings->deployment_failure_discord_notifications;
            $this->statusChangeDiscordNotifications = $this->settings->status_change_discord_notifications;
            $this->backupSuccessDiscordNotifications = $this->settings->backup_success_discord_notifications;
            $this->backupFailureDiscordNotifications = $this->settings->backup_failure_discord_notifications;
            $this->scheduledTaskSuccessDiscordNotifications = $this->settings->scheduled_task_success_discord_notifications;
            $this->scheduledTaskFailureDiscordNotifications = $this->settings->scheduled_task_failure_discord_notifications;
            $this->dockerCleanupSuccessDiscordNotifications = $this->settings->docker_cleanup_success_discord_notifications;
            $this->dockerCleanupFailureDiscordNotifications = $this->settings->docker_cleanup_failure_discord_notifications;
            $this->serverDiskUsageDiscordNotifications = $this->settings->server_disk_usage_discord_notifications;
            $this->serverReachableDiscordNotifications = $this->settings->server_reachable_discord_notifications;
            $this->serverUnreachableDiscordNotifications = $this->settings->server_unreachable_discord_notifications;
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
            $this->team->notify(new Test(channel: 'discord'));
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
