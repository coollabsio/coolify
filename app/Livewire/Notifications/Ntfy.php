<?php

namespace App\Livewire\Notifications;

use App\Livewire\Notifications\Concerns\TogglesNotificationEvents;
use App\Models\NtfyNotificationSettings;
use App\Models\Team;
use App\Notifications\Test;
use App\Rules\SafeWebhookUrl;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Ntfy extends Component
{
    use AuthorizesRequests, TogglesNotificationEvents;

    protected $listeners = ['refresh' => '$refresh'];

    #[Locked]
    public Team $team;

    #[Locked]
    public NtfyNotificationSettings $settings;

    #[Validate(['boolean'])]
    public bool $ntfyEnabled = false;

    #[Validate(['nullable', new SafeWebhookUrl])]
    public ?string $ntfyUrl = null;

    #[Validate(['nullable', 'string', 'max:64', 'regex:/\A[a-zA-Z0-9_-]+\z/'])]
    public ?string $ntfyTopic = null;

    #[Validate(['string', 'in:none,token,basic'])]
    public string $ntfyAuthMethod = 'basic';

    #[Validate(['nullable', 'string', 'max:255', 'not_regex:/[\x00-\x1F\x7F]/'])]
    public ?string $ntfyToken = null;

    #[Validate(['nullable', 'string', 'max:255', 'not_regex:/[\x00-\x1F\x7F]/'])]
    public ?string $ntfyUsername = null;

    #[Validate(['nullable', 'string', 'max:255', 'not_regex:/[\x00-\x1F\x7F]/'])]
    public ?string $ntfyPassword = null;

    #[Validate(['integer', 'min:1', 'max:5'])]
    public int $ntfyPrioritySuccessEvents = 2;

    #[Validate(['integer', 'min:1', 'max:5'])]
    public int $ntfyPriorityInfoEvents = 3;

    #[Validate(['integer', 'min:1', 'max:5'])]
    public int $ntfyPriorityWarningEvents = 4;

    #[Validate(['integer', 'min:1', 'max:5'])]
    public int $ntfyPriorityErrorEvents = 5;

    #[Validate(['boolean'])]
    public bool $deploymentSuccessNtfyNotifications = false;

    #[Validate(['boolean'])]
    public bool $deploymentFailureNtfyNotifications = true;

    #[Validate(['boolean'])]
    public bool $statusChangeNtfyNotifications = false;

    #[Validate(['boolean'])]
    public bool $restartLimitReachedNtfyNotifications = true;

    #[Validate(['boolean'])]
    public bool $backupSuccessNtfyNotifications = false;

    #[Validate(['boolean'])]
    public bool $backupFailureNtfyNotifications = true;

    #[Validate(['boolean'])]
    public bool $scheduledTaskSuccessNtfyNotifications = false;

    #[Validate(['boolean'])]
    public bool $scheduledTaskFailureNtfyNotifications = true;

    #[Validate(['boolean'])]
    public bool $dockerCleanupSuccessNtfyNotifications = false;

    #[Validate(['boolean'])]
    public bool $dockerCleanupFailureNtfyNotifications = true;

    #[Validate(['boolean'])]
    public bool $serverDiskUsageNtfyNotifications = true;

    #[Validate(['boolean'])]
    public bool $serverReachableNtfyNotifications = false;

    #[Validate(['boolean'])]
    public bool $serverUnreachableNtfyNotifications = true;

    #[Validate(['boolean'])]
    public bool $serverPatchNtfyNotifications = false;

    #[Validate(['boolean'])]
    public bool $serverAutoUpdateNtfyNotifications = true;

    #[Validate(['boolean'])]
    public bool $traefikOutdatedNtfyNotifications = true;

    #[Validate(['boolean'])]
    public bool $imageVulnerabilitiesNtfyNotifications = false;

    public function mount()
    {
        try {
            $this->team = auth()->user()->currentTeam();
            $this->settings = $this->team->ntfyNotificationSettings;
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
            $this->settings->ntfy_enabled = $this->ntfyEnabled;
            $this->settings->ntfy_url = $this->ntfyUrl;
            $this->settings->ntfy_topic = $this->ntfyTopic;
            $this->settings->ntfy_auth_method = $this->ntfyAuthMethod;
            if ($this->ntfyAuthMethod === 'token') {
                $this->settings->ntfy_token = $this->ntfyToken;
                $this->settings->ntfy_username = null;
                $this->settings->ntfy_password = null;
            } elseif ($this->ntfyAuthMethod === 'basic') {
                $this->settings->ntfy_token = null;
                $this->settings->ntfy_username = $this->ntfyUsername;
                $this->settings->ntfy_password = $this->ntfyPassword;
            } else {
                $this->settings->ntfy_token = null;
                $this->settings->ntfy_username = null;
                $this->settings->ntfy_password = null;
            }

            $this->settings->ntfy_priority_success_events = $this->ntfyPrioritySuccessEvents;
            $this->settings->ntfy_priority_info_events = $this->ntfyPriorityInfoEvents;
            $this->settings->ntfy_priority_warning_events = $this->ntfyPriorityWarningEvents;
            $this->settings->ntfy_priority_error_events = $this->ntfyPriorityErrorEvents;

            $this->settings->deployment_success_ntfy_notifications = $this->deploymentSuccessNtfyNotifications;
            $this->settings->deployment_failure_ntfy_notifications = $this->deploymentFailureNtfyNotifications;
            $this->settings->status_change_ntfy_notifications = $this->statusChangeNtfyNotifications;
            $this->settings->restart_limit_reached_ntfy_notifications = $this->restartLimitReachedNtfyNotifications;
            $this->settings->backup_success_ntfy_notifications = $this->backupSuccessNtfyNotifications;
            $this->settings->backup_failure_ntfy_notifications = $this->backupFailureNtfyNotifications;
            $this->settings->scheduled_task_success_ntfy_notifications = $this->scheduledTaskSuccessNtfyNotifications;
            $this->settings->scheduled_task_failure_ntfy_notifications = $this->scheduledTaskFailureNtfyNotifications;
            $this->settings->docker_cleanup_success_ntfy_notifications = $this->dockerCleanupSuccessNtfyNotifications;
            $this->settings->docker_cleanup_failure_ntfy_notifications = $this->dockerCleanupFailureNtfyNotifications;
            $this->settings->server_disk_usage_ntfy_notifications = $this->serverDiskUsageNtfyNotifications;
            $this->settings->server_reachable_ntfy_notifications = $this->serverReachableNtfyNotifications;
            $this->settings->server_unreachable_ntfy_notifications = $this->serverUnreachableNtfyNotifications;
            $this->settings->server_patch_ntfy_notifications = $this->serverPatchNtfyNotifications;
            $this->settings->server_auto_update_ntfy_notifications = $this->serverAutoUpdateNtfyNotifications;
            $this->settings->traefik_outdated_ntfy_notifications = $this->traefikOutdatedNtfyNotifications;
            $this->settings->image_vulnerabilities_ntfy_notifications = $this->imageVulnerabilitiesNtfyNotifications;

            $changedFields = array_keys($this->settings->getDirty());
            $this->settings->save();
            $this->auditNotificationSettings($changedFields);
            refreshSession();
        } else {
            $this->ntfyEnabled = $this->settings->ntfy_enabled;
            if (auth()->user()->can('update', $this->settings)) {
                $this->ntfyUrl = $this->settings->ntfy_url;
                $this->ntfyTopic = $this->settings->ntfy_topic;
                $this->ntfyToken = $this->settings->ntfy_token;
                $this->ntfyUsername = $this->settings->ntfy_username;
                $this->ntfyPassword = $this->settings->ntfy_password;
            } else {
                $this->ntfyUrl = null;
                $this->ntfyTopic = null;
                $this->ntfyToken = null;
                $this->ntfyUsername = null;
                $this->ntfyPassword = null;
            }
            $this->ntfyAuthMethod = $this->settings->ntfy_auth_method ?? 'basic';

            $this->ntfyPrioritySuccessEvents = $this->settings->ntfy_priority_success_events;
            $this->ntfyPriorityInfoEvents = $this->settings->ntfy_priority_info_events;
            $this->ntfyPriorityWarningEvents = $this->settings->ntfy_priority_warning_events;
            $this->ntfyPriorityErrorEvents = $this->settings->ntfy_priority_error_events;

            $this->deploymentSuccessNtfyNotifications = $this->settings->deployment_success_ntfy_notifications;
            $this->deploymentFailureNtfyNotifications = $this->settings->deployment_failure_ntfy_notifications;
            $this->statusChangeNtfyNotifications = $this->settings->status_change_ntfy_notifications;
            $this->restartLimitReachedNtfyNotifications = $this->settings->restart_limit_reached_ntfy_notifications;
            $this->backupSuccessNtfyNotifications = $this->settings->backup_success_ntfy_notifications;
            $this->backupFailureNtfyNotifications = $this->settings->backup_failure_ntfy_notifications;
            $this->scheduledTaskSuccessNtfyNotifications = $this->settings->scheduled_task_success_ntfy_notifications;
            $this->scheduledTaskFailureNtfyNotifications = $this->settings->scheduled_task_failure_ntfy_notifications;
            $this->dockerCleanupSuccessNtfyNotifications = $this->settings->docker_cleanup_success_ntfy_notifications;
            $this->dockerCleanupFailureNtfyNotifications = $this->settings->docker_cleanup_failure_ntfy_notifications;
            $this->serverDiskUsageNtfyNotifications = $this->settings->server_disk_usage_ntfy_notifications;
            $this->serverReachableNtfyNotifications = $this->settings->server_reachable_ntfy_notifications;
            $this->serverUnreachableNtfyNotifications = $this->settings->server_unreachable_ntfy_notifications;
            $this->serverPatchNtfyNotifications = $this->settings->server_patch_ntfy_notifications;
            $this->serverAutoUpdateNtfyNotifications = $this->settings->server_auto_update_ntfy_notifications;
            $this->traefikOutdatedNtfyNotifications = $this->settings->traefik_outdated_ntfy_notifications;
            $this->imageVulnerabilitiesNtfyNotifications = $this->settings->image_vulnerabilities_ntfy_notifications;
        }
    }

    public function instantSaveNtfyEnabled()
    {
        try {
            $this->validateServerAndTopic();
            $this->saveModel();
        } catch (\Throwable $e) {
            $this->ntfyEnabled = (bool) $this->settings->refresh()->ntfy_enabled;

            return handleError($e, $this);
        } finally {
            $this->dispatch('refresh');
        }
    }

    public function toggleNtfyEnabled()
    {
        try {
            $this->resetErrorBag();

            if ($this->ntfyEnabled) {
                $this->ntfyEnabled = false;
            } else {
                $this->validateServerAndTopic();
                $this->ntfyEnabled = true;
            }

            $this->saveModel();
        } catch (\Throwable $e) {
            $this->syncData();

            return handleError($e, $this);
        } finally {
            $this->dispatch('refresh');
        }
    }

    private function validateServerAndTopic(): void
    {
        $this->validate([
            'ntfyUrl' => ['required', new SafeWebhookUrl],
            'ntfyTopic' => ['required', 'max:64', 'regex:/\A[a-zA-Z0-9_-]+\z/'],
        ], [
            'ntfyUrl.required' => 'Ntfy Server URL is required.',
            'ntfyTopic.required' => 'Ntfy Topic is required.',
            'ntfyTopic.regex' => 'Ntfy Topic may only contain letters, numbers, hyphens, and underscores.',
        ]);
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
            $this->team->notify(new Test(channel: 'ntfy'));
            $this->dispatch('success', 'Test notification sent.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.notifications.ntfy');
    }

    private function auditNotificationSettings(array $changedFields): void
    {
        if ($changedFields !== []) {
            auditLog('ui.notifications.ntfy.updated', ['team_id' => $this->team->id, 'changed_fields' => $changedFields]);
        }
    }
}
