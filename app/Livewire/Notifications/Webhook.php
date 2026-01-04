<?php

namespace App\Livewire\Notifications;

use App\Models\Team;
use App\Models\WebhookNotificationSettings;
use App\Notifications\Test;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Webhook extends Component
{
    use AuthorizesRequests;

    public Team $team;

    public WebhookNotificationSettings $settings;

    #[Validate(['boolean'])]
    public bool $webhookEnabled = false;

    #[Validate(['url', 'nullable'])]
    public ?string $webhookUrl = null;

    #[Validate(['boolean'])]
    public bool $deploymentSuccessWebhookNotifications = false;

    #[Validate(['boolean'])]
    public bool $deploymentFailureWebhookNotifications = true;

    #[Validate(['boolean'])]
    public bool $statusChangeWebhookNotifications = false;

    #[Validate(['boolean'])]
    public bool $backupSuccessWebhookNotifications = false;

    #[Validate(['boolean'])]
    public bool $backupFailureWebhookNotifications = true;

    #[Validate(['boolean'])]
    public bool $scheduledTaskSuccessWebhookNotifications = false;

    #[Validate(['boolean'])]
    public bool $scheduledTaskFailureWebhookNotifications = true;

    #[Validate(['boolean'])]
    public bool $dockerCleanupSuccessWebhookNotifications = false;

    #[Validate(['boolean'])]
    public bool $dockerCleanupFailureWebhookNotifications = true;

    #[Validate(['boolean'])]
    public bool $serverDiskUsageWebhookNotifications = true;

    #[Validate(['boolean'])]
    public bool $serverReachableWebhookNotifications = false;

    #[Validate(['boolean'])]
    public bool $serverUnreachableWebhookNotifications = true;

    #[Validate(['boolean'])]
    public bool $serverPatchWebhookNotifications = false;

    #[Validate(['boolean'])]
    public bool $traefikOutdatedWebhookNotifications = true;

    public function mount()
    {
        try {
            $this->team = auth()->user()->currentTeam();
            $this->settings = $this->team->webhookNotificationSettings;
            $this->authorize('view', $this->settings);
            $this->syncData();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function syncData(bool $toModel = false)
    {
        if ($toModel) {
            $this->validate();
            $this->authorize('update', $this->settings);
            $this->settings->webhook_enabled = $this->webhookEnabled;
            $this->settings->webhook_url = $this->webhookUrl;

            $this->settings->deployment_success_webhook_notifications = $this->deploymentSuccessWebhookNotifications;
            $this->settings->deployment_failure_webhook_notifications = $this->deploymentFailureWebhookNotifications;
            $this->settings->status_change_webhook_notifications = $this->statusChangeWebhookNotifications;
            $this->settings->backup_success_webhook_notifications = $this->backupSuccessWebhookNotifications;
            $this->settings->backup_failure_webhook_notifications = $this->backupFailureWebhookNotifications;
            $this->settings->scheduled_task_success_webhook_notifications = $this->scheduledTaskSuccessWebhookNotifications;
            $this->settings->scheduled_task_failure_webhook_notifications = $this->scheduledTaskFailureWebhookNotifications;
            $this->settings->docker_cleanup_success_webhook_notifications = $this->dockerCleanupSuccessWebhookNotifications;
            $this->settings->docker_cleanup_failure_webhook_notifications = $this->dockerCleanupFailureWebhookNotifications;
            $this->settings->server_disk_usage_webhook_notifications = $this->serverDiskUsageWebhookNotifications;
            $this->settings->server_reachable_webhook_notifications = $this->serverReachableWebhookNotifications;
            $this->settings->server_unreachable_webhook_notifications = $this->serverUnreachableWebhookNotifications;
            $this->settings->server_patch_webhook_notifications = $this->serverPatchWebhookNotifications;
            $this->settings->traefik_outdated_webhook_notifications = $this->traefikOutdatedWebhookNotifications;

            $this->settings->save();
            refreshSession();
        } else {
            $this->webhookEnabled = $this->settings->webhook_enabled;
            $this->webhookUrl = $this->settings->webhook_url;

            $this->deploymentSuccessWebhookNotifications = $this->settings->deployment_success_webhook_notifications;
            $this->deploymentFailureWebhookNotifications = $this->settings->deployment_failure_webhook_notifications;
            $this->statusChangeWebhookNotifications = $this->settings->status_change_webhook_notifications;
            $this->backupSuccessWebhookNotifications = $this->settings->backup_success_webhook_notifications;
            $this->backupFailureWebhookNotifications = $this->settings->backup_failure_webhook_notifications;
            $this->scheduledTaskSuccessWebhookNotifications = $this->settings->scheduled_task_success_webhook_notifications;
            $this->scheduledTaskFailureWebhookNotifications = $this->settings->scheduled_task_failure_webhook_notifications;
            $this->dockerCleanupSuccessWebhookNotifications = $this->settings->docker_cleanup_success_webhook_notifications;
            $this->dockerCleanupFailureWebhookNotifications = $this->settings->docker_cleanup_failure_webhook_notifications;
            $this->serverDiskUsageWebhookNotifications = $this->settings->server_disk_usage_webhook_notifications;
            $this->serverReachableWebhookNotifications = $this->settings->server_reachable_webhook_notifications;
            $this->serverUnreachableWebhookNotifications = $this->settings->server_unreachable_webhook_notifications;
            $this->serverPatchWebhookNotifications = $this->settings->server_patch_webhook_notifications;
            $this->traefikOutdatedWebhookNotifications = $this->settings->traefik_outdated_webhook_notifications;
        }
    }

    public function instantSaveWebhookEnabled()
    {
        try {
            $original = $this->webhookEnabled;
            $this->validate([
                'webhookUrl' => 'required',
            ], [
                'webhookUrl.required' => 'Webhook URL is required.',
            ]);
            $this->saveModel();
        } catch (\Throwable $e) {
            $this->webhookEnabled = $original;

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

        if (isDev()) {
            ray('Webhook settings saved', [
                'webhook_enabled' => $this->settings->webhook_enabled,
                'webhook_url' => $this->settings->webhook_url,
            ]);
        }

        $this->dispatch('success', 'Settings saved.');
    }

    public function sendTestNotification()
    {
        try {
            $this->authorize('sendTest', $this->settings);

            if (isDev()) {
                ray('Sending test webhook notification', [
                    'team_id' => $this->team->id,
                    'webhook_url' => $this->settings->webhook_url,
                ]);
            }

            $this->team->notify(new Test(channel: 'webhook'));
            $this->dispatch('success', 'Test notification sent.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.notifications.webhook');
    }
}
