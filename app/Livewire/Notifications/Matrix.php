<?php

namespace App\Livewire\Notifications;

use App\Models\MatrixNotificationSettings;
use App\Models\Team;
use App\Notifications\Test;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Matrix extends Component
{
    use AuthorizesRequests;

    protected $listeners = ['refresh' => '$refresh'];

    #[Locked]
    public Team $team;

    #[Locked]
    public MatrixNotificationSettings $settings;

    #[Validate(['boolean'])]
    public bool $matrixEnabled = false;

    #[Validate(['url', 'nullable'])]
    public ?string $matrixHomeserverUrl = null;

    #[Validate(['string', 'nullable'])]
    public ?string $matrixRoomId = null;

    #[Validate(['string', 'nullable'])]
    public ?string $matrixAccessToken = null;

    #[Validate(['string', 'nullable'])]
    public ?string $matrixFriendlyName = null;

    #[Validate(['boolean'])]
    public bool $deploymentSuccessMatrixNotifications = false;

    #[Validate(['boolean'])]
    public bool $deploymentFailureMatrixNotifications = true;

    #[Validate(['boolean'])]
    public bool $statusChangeMatrixNotifications = false;

    #[Validate(['boolean'])]
    public bool $backupSuccessMatrixNotifications = false;

    #[Validate(['boolean'])]
    public bool $backupFailureMatrixNotifications = true;

    #[Validate(['boolean'])]
    public bool $scheduledTaskSuccessMatrixNotifications = false;

    #[Validate(['boolean'])]
    public bool $scheduledTaskFailureMatrixNotifications = true;

    #[Validate(['boolean'])]
    public bool $dockerCleanupSuccessMatrixNotifications = false;

    #[Validate(['boolean'])]
    public bool $dockerCleanupFailureMatrixNotifications = true;

    #[Validate(['boolean'])]
    public bool $serverDiskUsageMatrixNotifications = true;

    #[Validate(['boolean'])]
    public bool $serverReachableMatrixNotifications = false;

    #[Validate(['boolean'])]
    public bool $serverUnreachableMatrixNotifications = true;

    #[Validate(['boolean'])]
    public bool $serverPatchMatrixNotifications = false;

    public function mount()
    {
        try {
            $this->team = auth()->user()->currentTeam();
            $this->settings = $this->team->matrixNotificationSettings;
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
            $this->settings->matrix_enabled = $this->matrixEnabled;
            $this->settings->matrix_homeserver_url = $this->matrixHomeserverUrl;
            $this->settings->matrix_room_id = $this->matrixRoomId;
            $this->settings->matrix_access_token = $this->matrixAccessToken;
            $this->settings->matrix_friendly_name = $this->matrixFriendlyName;

            $this->settings->deployment_success_matrix_notifications = $this->deploymentSuccessMatrixNotifications;
            $this->settings->deployment_failure_matrix_notifications = $this->deploymentFailureMatrixNotifications;
            $this->settings->status_change_matrix_notifications = $this->statusChangeMatrixNotifications;
            $this->settings->backup_success_matrix_notifications = $this->backupSuccessMatrixNotifications;
            $this->settings->backup_failure_matrix_notifications = $this->backupFailureMatrixNotifications;
            $this->settings->scheduled_task_success_matrix_notifications = $this->scheduledTaskSuccessMatrixNotifications;
            $this->settings->scheduled_task_failure_matrix_notifications = $this->scheduledTaskFailureMatrixNotifications;
            $this->settings->docker_cleanup_success_matrix_notifications = $this->dockerCleanupSuccessMatrixNotifications;
            $this->settings->docker_cleanup_failure_matrix_notifications = $this->dockerCleanupFailureMatrixNotifications;
            $this->settings->server_disk_usage_matrix_notifications = $this->serverDiskUsageMatrixNotifications;
            $this->settings->server_reachable_matrix_notifications = $this->serverReachableMatrixNotifications;
            $this->settings->server_unreachable_matrix_notifications = $this->serverUnreachableMatrixNotifications;
            $this->settings->server_patch_matrix_notifications = $this->serverPatchMatrixNotifications;

            $this->settings->save();
            refreshSession();
        } else {
            $this->matrixEnabled = $this->settings->matrix_enabled;
            $this->matrixHomeserverUrl = $this->settings->matrix_homeserver_url;
            $this->matrixRoomId = $this->settings->matrix_room_id;
            $this->matrixAccessToken = $this->settings->matrix_access_token;
            $this->matrixFriendlyName = $this->settings->matrix_friendly_name;

            $this->deploymentSuccessMatrixNotifications = $this->settings->deployment_success_matrix_notifications;
            $this->deploymentFailureMatrixNotifications = $this->settings->deployment_failure_matrix_notifications;
            $this->statusChangeMatrixNotifications = $this->settings->status_change_matrix_notifications;
            $this->backupSuccessMatrixNotifications = $this->settings->backup_success_matrix_notifications;
            $this->backupFailureMatrixNotifications = $this->settings->backup_failure_matrix_notifications;
            $this->scheduledTaskSuccessMatrixNotifications = $this->settings->scheduled_task_success_matrix_notifications;
            $this->scheduledTaskFailureMatrixNotifications = $this->settings->scheduled_task_failure_matrix_notifications;
            $this->dockerCleanupSuccessMatrixNotifications = $this->settings->docker_cleanup_success_matrix_notifications;
            $this->dockerCleanupFailureMatrixNotifications = $this->settings->docker_cleanup_failure_matrix_notifications;
            $this->serverDiskUsageMatrixNotifications = $this->settings->server_disk_usage_matrix_notifications;
            $this->serverReachableMatrixNotifications = $this->settings->server_reachable_matrix_notifications;
            $this->serverUnreachableMatrixNotifications = $this->settings->server_unreachable_matrix_notifications;
            $this->serverPatchMatrixNotifications = $this->settings->server_patch_matrix_notifications;
        }
    }

    public function instantSaveMatrixEnabled()
    {
        try {
            $this->validate([
                'matrixHomeserverUrl' => 'required',
                'matrixRoomId' => 'required',
                'matrixAccessToken' => 'required',
            ], [
                'matrixHomeserverUrl.required' => 'Matrix Homeserver URL is required.',
                'matrixRoomId.required' => 'Matrix Room ID is required.',
                'matrixAccessToken.required' => 'Matrix Access Token is required.',
            ]);
            $this->saveModel();
        } catch (\Throwable $e) {
            $this->matrixEnabled = false;

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
            $this->team->notify(new Test(channel: 'matrix'));
            $this->dispatch('success', 'Test notification sent.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.notifications.matrix');
    }
}