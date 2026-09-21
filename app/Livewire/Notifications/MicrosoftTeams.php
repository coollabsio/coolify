<?php

namespace App\Livewire\Notifications;

use App\Livewire\Notifications\Concerns\TogglesNotificationEvents;
use App\Models\MicrosoftTeamsNotificationSettings;
use App\Models\Team;
use App\Notifications\Test;
use App\Rules\SafeWebhookUrl;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;

class MicrosoftTeams extends Component
{
    use AuthorizesRequests, TogglesNotificationEvents;

    protected $listeners = ['refresh' => '$refresh'];

    #[Locked]
    public Team $team;

    #[Locked]
    public MicrosoftTeamsNotificationSettings $settings;

    #[Validate(['boolean'])]
    public bool $microsoftTeamsEnabled = false;

    #[Validate(['nullable', new SafeWebhookUrl])]
    public ?string $microsoftTeamsWebhookUrl = null;

    #[Validate(['boolean'])]
    public bool $deploymentSuccessMicrosoftTeamsNotifications = false;

    #[Validate(['boolean'])]
    public bool $deploymentFailureMicrosoftTeamsNotifications = true;

    #[Validate(['boolean'])]
    public bool $statusChangeMicrosoftTeamsNotifications = false;

    #[Validate(['boolean'])]
    public bool $restartLimitReachedMicrosoftTeamsNotifications = true;

    #[Validate(['boolean'])]
    public bool $backupSuccessMicrosoftTeamsNotifications = false;

    #[Validate(['boolean'])]
    public bool $backupFailureMicrosoftTeamsNotifications = true;

    #[Validate(['boolean'])]
    public bool $scheduledTaskSuccessMicrosoftTeamsNotifications = false;

    #[Validate(['boolean'])]
    public bool $scheduledTaskFailureMicrosoftTeamsNotifications = true;

    #[Validate(['boolean'])]
    public bool $dockerCleanupSuccessMicrosoftTeamsNotifications = false;

    #[Validate(['boolean'])]
    public bool $dockerCleanupFailureMicrosoftTeamsNotifications = true;

    #[Validate(['boolean'])]
    public bool $serverDiskUsageMicrosoftTeamsNotifications = true;

    #[Validate(['boolean'])]
    public bool $serverReachableMicrosoftTeamsNotifications = false;

    #[Validate(['boolean'])]
    public bool $serverUnreachableMicrosoftTeamsNotifications = true;

    #[Validate(['boolean'])]
    public bool $serverPatchMicrosoftTeamsNotifications = false;

    #[Validate(['boolean'])]
    public bool $traefikOutdatedMicrosoftTeamsNotifications = true;

    public function mount()
    {
        try {
            $this->team = auth()->user()->currentTeam();
            $this->settings = $this->team->microsoftTeamsNotificationSettings;
            $this->authorize('view', $this->settings);
            $this->syncData();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    private function syncData(bool $toModel = false): void
    {
        if ($toModel) {
            $this->validate();
            $this->settings->microsoft_teams_enabled = $this->microsoftTeamsEnabled;
            $this->settings->microsoft_teams_webhook_url = $this->microsoftTeamsWebhookUrl;

            $this->settings->deployment_success_microsoft_teams_notifications = $this->deploymentSuccessMicrosoftTeamsNotifications;
            $this->settings->deployment_failure_microsoft_teams_notifications = $this->deploymentFailureMicrosoftTeamsNotifications;
            $this->settings->status_change_microsoft_teams_notifications = $this->statusChangeMicrosoftTeamsNotifications;
            $this->settings->restart_limit_reached_microsoft_teams_notifications = $this->restartLimitReachedMicrosoftTeamsNotifications;
            $this->settings->backup_success_microsoft_teams_notifications = $this->backupSuccessMicrosoftTeamsNotifications;
            $this->settings->backup_failure_microsoft_teams_notifications = $this->backupFailureMicrosoftTeamsNotifications;
            $this->settings->scheduled_task_success_microsoft_teams_notifications = $this->scheduledTaskSuccessMicrosoftTeamsNotifications;
            $this->settings->scheduled_task_failure_microsoft_teams_notifications = $this->scheduledTaskFailureMicrosoftTeamsNotifications;
            $this->settings->docker_cleanup_success_microsoft_teams_notifications = $this->dockerCleanupSuccessMicrosoftTeamsNotifications;
            $this->settings->docker_cleanup_failure_microsoft_teams_notifications = $this->dockerCleanupFailureMicrosoftTeamsNotifications;
            $this->settings->server_disk_usage_microsoft_teams_notifications = $this->serverDiskUsageMicrosoftTeamsNotifications;
            $this->settings->server_reachable_microsoft_teams_notifications = $this->serverReachableMicrosoftTeamsNotifications;
            $this->settings->server_unreachable_microsoft_teams_notifications = $this->serverUnreachableMicrosoftTeamsNotifications;
            $this->settings->server_patch_microsoft_teams_notifications = $this->serverPatchMicrosoftTeamsNotifications;
            $this->settings->traefik_outdated_microsoft_teams_notifications = $this->traefikOutdatedMicrosoftTeamsNotifications;

            $this->settings->save();
            refreshSession();
        } else {
            $this->microsoftTeamsEnabled = $this->settings->microsoft_teams_enabled;
            $this->microsoftTeamsWebhookUrl = auth()->user()->can('update', $this->settings)
                ? $this->settings->microsoft_teams_webhook_url
                : null;

            $this->deploymentSuccessMicrosoftTeamsNotifications = $this->settings->deployment_success_microsoft_teams_notifications;
            $this->deploymentFailureMicrosoftTeamsNotifications = $this->settings->deployment_failure_microsoft_teams_notifications;
            $this->statusChangeMicrosoftTeamsNotifications = $this->settings->status_change_microsoft_teams_notifications;
            $this->restartLimitReachedMicrosoftTeamsNotifications = $this->settings->restart_limit_reached_microsoft_teams_notifications;
            $this->backupSuccessMicrosoftTeamsNotifications = $this->settings->backup_success_microsoft_teams_notifications;
            $this->backupFailureMicrosoftTeamsNotifications = $this->settings->backup_failure_microsoft_teams_notifications;
            $this->scheduledTaskSuccessMicrosoftTeamsNotifications = $this->settings->scheduled_task_success_microsoft_teams_notifications;
            $this->scheduledTaskFailureMicrosoftTeamsNotifications = $this->settings->scheduled_task_failure_microsoft_teams_notifications;
            $this->dockerCleanupSuccessMicrosoftTeamsNotifications = $this->settings->docker_cleanup_success_microsoft_teams_notifications;
            $this->dockerCleanupFailureMicrosoftTeamsNotifications = $this->settings->docker_cleanup_failure_microsoft_teams_notifications;
            $this->serverDiskUsageMicrosoftTeamsNotifications = $this->settings->server_disk_usage_microsoft_teams_notifications;
            $this->serverReachableMicrosoftTeamsNotifications = $this->settings->server_reachable_microsoft_teams_notifications;
            $this->serverUnreachableMicrosoftTeamsNotifications = $this->settings->server_unreachable_microsoft_teams_notifications;
            $this->serverPatchMicrosoftTeamsNotifications = $this->settings->server_patch_microsoft_teams_notifications;
            $this->traefikOutdatedMicrosoftTeamsNotifications = $this->settings->traefik_outdated_microsoft_teams_notifications;
        }
    }

    public function instantSaveMicrosoftTeamsEnabled()
    {
        try {
            $this->validate([
                'microsoftTeamsWebhookUrl' => 'required',
            ], [
                'microsoftTeamsWebhookUrl.required' => 'Microsoft Teams Webhook URL is required.',
            ]);
            $this->saveModel();
        } catch (\Throwable $e) {
            $this->microsoftTeamsEnabled = false;

            return handleError($e, $this);
        } finally {
            $this->dispatch('refresh');
        }
    }

    public function instantSave()
    {
        try {
            $this->authorize('update', $this->settings);
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
            $this->authorize('update', $this->settings);
            $this->syncData(true);
            $this->saveModel();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function saveModel()
    {
        $this->authorize('update', $this->settings);

        $this->syncData(true);
        refreshSession();
        $this->dispatch('success', 'Settings saved.');
    }

    public function sendTestNotification()
    {
        try {
            $this->authorize('sendTest', $this->settings);
            $this->team->notify(new Test(channel: 'microsoft_teams'));
            $this->dispatch('success', 'Test notification sent.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.notifications.microsoft-teams');
    }
}
