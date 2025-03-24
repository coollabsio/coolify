<?php

namespace App\Livewire\Notifications;

use App\Models\Team;
use App\Models\TeamsNotificationSettings;
use App\Notifications\Test;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Teams extends Component
{
    protected $listeners = ['refresh' => '$refresh'];

    #[Locked]
    public Team $team;

    #[Locked]
    public TeamsNotificationSettings $settings;

    #[Validate(['boolean'])]
    public bool $teamsEnabled = false;

    #[Validate(['nullable', 'string'])]
    public ?string $teamsWebhookUrl = null;

    #[Validate(['boolean'])]
    public bool $deploymentSuccessTeamsNotifications = false;

    #[Validate(['boolean'])]
    public bool $deploymentFailureTeamsNotifications = true;

    #[Validate(['boolean'])]
    public bool $statusChangeTeamsNotifications = false;

    #[Validate(['boolean'])]
    public bool $backupSuccessTeamsNotifications = false;

    #[Validate(['boolean'])]
    public bool $backupFailureTeamsNotifications = true;

    #[Validate(['boolean'])]
    public bool $scheduledTaskSuccessTeamsNotifications = false;

    #[Validate(['boolean'])]
    public bool $scheduledTaskFailureTeamsNotifications = true;

    #[Validate(['boolean'])]
    public bool $dockerCleanupSuccessTeamsNotifications = false;

    #[Validate(['boolean'])]
    public bool $dockerCleanupFailureTeamsNotifications = true;

    #[Validate(['boolean'])]
    public bool $serverDiskUsageTeamsNotifications = true;

    #[Validate(['boolean'])]
    public bool $serverReachableTeamsNotifications = false;

    #[Validate(['boolean'])]
    public bool $serverUnreachableTeamsNotifications = true;

    public function mount()
    {
        try {
            $this->team = auth()->user()->currentTeam();
            $this->settings = $this->team->teamsNotificationSettings;
            $this->syncData();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function syncData(bool $toModel = false)
    {
        if ($toModel) {
            $this->validate();
            $this->settings->teams_enabled = $this->teamsEnabled;
            $this->settings->teams_webhook_url = $this->teamsWebhookUrl;

            $this->settings->deployment_success_teams_notifications = $this->deploymentSuccessTeamsNotifications;
            $this->settings->deployment_failure_teams_notifications = $this->deploymentFailureTeamsNotifications;
            $this->settings->status_change_teams_notifications = $this->statusChangeTeamsNotifications;
            $this->settings->backup_success_teams_notifications = $this->backupSuccessTeamsNotifications;
            $this->settings->backup_failure_teams_notifications = $this->backupFailureTeamsNotifications;
            $this->settings->scheduled_task_success_teams_notifications = $this->scheduledTaskSuccessTeamsNotifications;
            $this->settings->scheduled_task_failure_teams_notifications = $this->scheduledTaskFailureTeamsNotifications;
            $this->settings->docker_cleanup_success_teams_notifications = $this->dockerCleanupSuccessTeamsNotifications;
            $this->settings->docker_cleanup_failure_teams_notifications = $this->dockerCleanupFailureTeamsNotifications;
            $this->settings->server_disk_usage_teams_notifications = $this->serverDiskUsageTeamsNotifications;
            $this->settings->server_reachable_teams_notifications = $this->serverReachableTeamsNotifications;
            $this->settings->server_unreachable_teams_notifications = $this->serverUnreachableTeamsNotifications;

            $this->settings->save();
        } else {
            $this->teamsEnabled = $this->settings->teams_enabled;
            $this->teamsWebhookUrl = $this->settings->teams_webhook_url;

            $this->deploymentSuccessTeamsNotifications = $this->settings->deployment_success_teams_notifications;
            $this->deploymentFailureTeamsNotifications = $this->settings->deployment_failure_teams_notifications;
            $this->statusChangeTeamsNotifications = $this->settings->status_change_teams_notifications;
            $this->backupSuccessTeamsNotifications = $this->settings->backup_success_teams_notifications;
            $this->backupFailureTeamsNotifications = $this->settings->backup_failure_teams_notifications;
            $this->scheduledTaskSuccessTeamsNotifications = $this->settings->scheduled_task_success_teams_notifications;
            $this->scheduledTaskFailureTeamsNotifications = $this->settings->scheduled_task_failure_teams_notifications;
            $this->dockerCleanupSuccessTeamsNotifications = $this->settings->docker_cleanup_success_teams_notifications;
            $this->dockerCleanupFailureTeamsNotifications = $this->settings->docker_cleanup_failure_teams_notifications;
            $this->serverDiskUsageTeamsNotifications = $this->settings->server_disk_usage_teams_notifications;
            $this->serverReachableTeamsNotifications = $this->settings->server_reachable_teams_notifications;
            $this->serverUnreachableTeamsNotifications = $this->settings->server_unreachable_teams_notifications;
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

    public function instantSaveTeamsEnabled()
    {
        try {
            $this->validate([
                'teamsWebhookUrl' => 'required',
            ], [
                'teamsWebhookUrl.required' => 'Microsoft Teams Webhook URL é obrigatória.',
            ]);
            $this->saveModel();
        } catch (\Throwable $e) {
            $this->teamsEnabled = false;

            return handleError($e, $this);
        } finally {
            $this->dispatch('refresh');
        }
    }

    public function saveModel()
    {
        $this->syncData(true);
        refreshSession();
        $this->dispatch('success', 'Configurações salvas.');
    }

    public function sendTestNotification()
    {
        try {
            $this->team->notify(new Test(channel: 'teams'));
            $this->dispatch('success', 'Notificação de teste enviada.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.notifications.teams');
    }
}
