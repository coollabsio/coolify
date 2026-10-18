<?php

namespace App\Livewire\Notifications;

use App\Livewire\Notifications\Concerns\TogglesNotificationEvents;
use App\Models\GotifyNotificationSettings;
use App\Models\Team;
use App\Notifications\Test;
use App\Rules\SafeWebhookUrl;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Gotify extends Component
{
    use AuthorizesRequests, TogglesNotificationEvents;

    protected $listeners = ['refresh' => '$refresh'];

    #[Locked]
    public Team $team;

    #[Locked]
    public GotifyNotificationSettings $settings;

    #[Validate(['boolean'])]
    public bool $gotifyEnabled = false;

    #[Validate(['nullable', new SafeWebhookUrl])]
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
    public bool $restartLimitReachedGotifyNotifications = true;

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

    #[Validate(['boolean'])]
    public bool $traefikOutdatedGotifyNotifications = true;

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

    private function syncData(bool $toModel = false): void
    {
        if ($toModel) {
            $this->validate();
            $this->settings->gotify_enabled = $this->gotifyEnabled;
            $this->settings->gotify_url = $this->gotifyUrl;
            $this->settings->gotify_token = $this->gotifyToken;

            $this->settings->deployment_success_gotify_notifications = $this->deploymentSuccessGotifyNotifications;
            $this->settings->deployment_failure_gotify_notifications = $this->deploymentFailureGotifyNotifications;
            $this->settings->status_change_gotify_notifications = $this->statusChangeGotifyNotifications;
            $this->settings->restart_limit_reached_gotify_notifications = $this->restartLimitReachedGotifyNotifications;
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
            $this->settings->traefik_outdated_gotify_notifications = $this->traefikOutdatedGotifyNotifications;

            $this->settings->save();
            refreshSession();
        } else {
            $this->gotifyEnabled = $this->settings->gotify_enabled;
            if (auth()->user()->can('update', $this->settings)) {
                $this->gotifyUrl = $this->settings->gotify_url;
                $this->gotifyToken = $this->settings->gotify_token;
            } else {
                $this->gotifyUrl = null;
                $this->gotifyToken = null;
            }

            $this->deploymentSuccessGotifyNotifications = $this->settings->deployment_success_gotify_notifications;
            $this->deploymentFailureGotifyNotifications = $this->settings->deployment_failure_gotify_notifications;
            $this->statusChangeGotifyNotifications = $this->settings->status_change_gotify_notifications;
            $this->restartLimitReachedGotifyNotifications = $this->settings->restart_limit_reached_gotify_notifications;
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
            $this->traefikOutdatedGotifyNotifications = $this->settings->traefik_outdated_gotify_notifications;
        }
    }

    public function instantSaveGotifyEnabled()
    {
        try {
            $this->validate([
                'gotifyUrl' => 'required',
                'gotifyToken' => 'required',
            ], [
                'gotifyUrl.required' => 'Gotify Server URL is required.',
                'gotifyToken.required' => 'Gotify Application Token is required.',
            ]);
            $this->saveModel();
        } catch (\Throwable $e) {
            $this->gotifyEnabled = false;

            return handleError($e, $this);
        } finally {
            $this->dispatch('refresh');
        }
    }

    public function toggleGotifyEnabled()
    {
        try {
            $this->resetErrorBag();

            if ($this->gotifyEnabled) {
                $this->gotifyEnabled = false;
            } else {
                $this->validate([
                    'gotifyUrl' => 'required',
                    'gotifyToken' => 'required',
                ], [
                    'gotifyUrl.required' => 'Gotify Server URL is required.',
                    'gotifyToken.required' => 'Gotify Application Token is required.',
                ]);
                $this->gotifyEnabled = true;
            }

            $this->saveModel();
        } catch (\Throwable $e) {
            $this->syncData();

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
