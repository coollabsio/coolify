<?php

namespace App\Livewire\Notifications;

use App\Models\GotifyNotificationSettings;
use App\Models\Team;
use App\Notifications\Test;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Gotify extends Component
{
    use AuthorizesRequests;

    protected $listeners = ['refresh' => '$refresh'];

    #[Locked]
    public Team $team;

    #[Locked]
    public GotifyNotificationSettings $settings;

    #[Validate(['boolean'])]
    public bool $gotifyEnabled = false;

    #[Validate(['nullable', 'string'])]
    public ?string $gotifyUrl = null;

    #[Validate(['nullable', 'string'])]
    public ?string $gotifyToken = null;

    #[Validate(['boolean'])]
    public bool $deploymentSuccessGotifyNotifications = false;

    #[Validate(['boolean'])]
    public bool $deploymentFailureGotifyNotifications = true;

    #[Validate(['boolean'])]
    public bool $statusChangeGotifyNotifications = false;

    #[Validate(['boolean'])]
    public bool $backupSuccessGotifyNotifications = false;

    #[Validate(['boolean'])]
    public bool $backupFailureGotifyNotifications = true;

    #[Validate(['boolean'])]
    public bool $scheduledTaskSuccessGotifyNotifications = false;

    #[Validate(['boolean'])]
    public bool $scheduledTaskFailureGotifyNotifications = true;

    #[Validate(['boolean'])]
    public bool $dockerCleanupSuccessGotifyNotifications = false;

    #[Validate(['boolean'])]
    public bool $dockerCleanupFailureGotifyNotifications = true;

    #[Validate(['boolean'])]
    public bool $serverDiskUsageGotifyNotifications = true;

    #[Validate(['boolean'])]
    public bool $serverReachableGotifyNotifications = false;

    #[Validate(['boolean'])]
    public bool $serverUnreachableGotifyNotifications = true;

    #[Validate(['boolean'])]
    public bool $serverPatchGotifyNotifications = false;

    public function mount()
    {
        try {
            $this->team = auth()->user()->currentTeam();
            $this->settings = $this->team->gotifyNotificationSettings;
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
            $this->settings->gotify_enabled = $this->gotifyEnabled;
            $this->settings->gotify_url = $this->gotifyUrl;
            $this->settings->gotify_token = $this->gotifyToken;

            $this->settings->deployment_success_gotify_notifications = $this->deploymentSuccessGotifyNotifications;
            $this->settings->deployment_failure_gotify_notifications = $this->deploymentFailureGotifyNotifications;
            $this->settings->status_change_gotify_notifications = $this->statusChangeGotifyNotifications;
            $this->settings->backup_success_gotify_notifications = $this->backupSuccessGotifyNotifications;
            $this->settings->backup_failure_gotify_notifications = $this->backupFailureGotifyNotifications;
            $this->settings->scheduled_task_success_gotify_notifications = $this->scheduledTaskSuccessGotifyNotifications;
            $this->settings->scheduled_task_failure_gotify_notifications = $this->scheduledTaskFailureGotifyNotifications;
            $this->settings->docker_cleanup_success_gotify_notifications = $this->dockerCleanupSuccessGotifyNotifications;
            $this->settings->docker_cleanup_failure_gotify_notifications = $this->dockerCleanupFailureGotifyNotifications;
            $this->settings->server_disk_usage_gotify_notifications = $this->serverDiskUsageGotifyNotifications;
            $this->settings->server_reachable_gotify_notifications = $this->serverReachableGotifyNotifications;
            $this->settings->server_unreachable_gotify_notifications = $this->serverUnreachableGotifyNotifications;
            $this->settings->server_patch_gotify_notifications = $this->serverPatchGotifyNotifications;

            $this->settings->save();
            refreshSession();
        } else {
            $this->gotifyEnabled = $this->settings->gotify_enabled;
            $this->gotifyUrl = $this->settings->gotify_url;
            $this->gotifyToken = $this->settings->gotify_token;

            $this->deploymentSuccessGotifyNotifications = $this->settings->deployment_success_gotify_notifications;
            $this->deploymentFailureGotifyNotifications = $this->settings->deployment_failure_gotify_notifications;
            $this->statusChangeGotifyNotifications = $this->settings->status_change_gotify_notifications;
            $this->backupSuccessGotifyNotifications = $this->settings->backup_success_gotify_notifications;
            $this->backupFailureGotifyNotifications = $this->settings->backup_failure_gotify_notifications;
            $this->scheduledTaskSuccessGotifyNotifications = $this->settings->scheduled_task_success_gotify_notifications;
            $this->scheduledTaskFailureGotifyNotifications = $this->settings->scheduled_task_failure_gotify_notifications;
            $this->dockerCleanupSuccessGotifyNotifications = $this->settings->docker_cleanup_success_gotify_notifications;
            $this->dockerCleanupFailureGotifyNotifications = $this->settings->docker_cleanup_failure_gotify_notifications;
            $this->serverDiskUsageGotifyNotifications = $this->settings->server_disk_usage_gotify_notifications;
            $this->serverReachableGotifyNotifications = $this->settings->server_reachable_gotify_notifications;
            $this->serverUnreachableGotifyNotifications = $this->settings->server_unreachable_gotify_notifications;
            $this->serverPatchGotifyNotifications = $this->settings->server_patch_gotify_notifications;
        }
    }

    public function instantSaveGotifyEnabled()
    {
        try {
            $this->validate([
                'gotifyUrl' => 'required',
                'gotifyToken' => 'required',
            ], [
                'gotifyUrl.required' => 'Gotify URL is required.',
                'gotifyToken.required' => 'Gotify Token is required.',
            ]);
            $this->saveModel();
        } catch (\Throwable $e) {
            $this->gotifyEnabled = false;

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
            $this->authorize('sendTest', $this->settings);
            $this->team->notify(new Test(channel: 'gotify'));
            $this->dispatch('success', 'Test notification sent.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.notifications.gotify');
    }
}
