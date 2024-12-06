<?php

namespace App\Livewire\Notifications;

use App\Models\Team;
use App\Notifications\Test;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Slack extends Component
{
    public Team $team;

    #[Validate(['boolean'])]
    public bool $slackEnabled = false;

    #[Validate(['url', 'nullable'])]
    public ?string $slackWebhookUrl = null;

    #[Validate(['boolean'])]
    public bool $slackNotificationsTest = false;

    #[Validate(['boolean'])]
    public bool $slackNotificationsDeployments = false;

    #[Validate(['boolean'])]
    public bool $slackNotificationsStatusChanges = false;

    #[Validate(['boolean'])]
    public bool $slackNotificationsDatabaseBackups = false;

    #[Validate(['boolean'])]
    public bool $slackNotificationsScheduledTasks = false;

    #[Validate(['boolean'])]
    public bool $slackNotificationsServerDiskUsage = false;

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
            $this->team->slack_enabled = $this->slackEnabled;
            $this->team->slack_webhook_url = $this->slackWebhookUrl;
            $this->team->slack_notifications_test = $this->slackNotificationsTest;
            $this->team->slack_notifications_deployments = $this->slackNotificationsDeployments;
            $this->team->slack_notifications_status_changes = $this->slackNotificationsStatusChanges;
            $this->team->slack_notifications_database_backups = $this->slackNotificationsDatabaseBackups;
            $this->team->slack_notifications_scheduled_tasks = $this->slackNotificationsScheduledTasks;
            $this->team->slack_notifications_server_disk_usage = $this->slackNotificationsServerDiskUsage;
            $this->team->save();
            refreshSession();
        } else {
            $this->slackEnabled = $this->team->slack_enabled;
            $this->slackWebhookUrl = $this->team->slack_webhook_url;
            $this->slackNotificationsTest = $this->team->slack_notifications_test;
            $this->slackNotificationsDeployments = $this->team->slack_notifications_deployments;
            $this->slackNotificationsStatusChanges = $this->team->slack_notifications_status_changes;
            $this->slackNotificationsDatabaseBackups = $this->team->slack_notifications_database_backups;
            $this->slackNotificationsScheduledTasks = $this->team->slack_notifications_scheduled_tasks;
            $this->slackNotificationsServerDiskUsage = $this->team->slack_notifications_server_disk_usage;
        }
    }

    public function instantSaveSlackEnabled()
    {
        try {
            $this->validate([
                'slackWebhookUrl' => 'required',
            ], [
                'slackWebhookUrl.required' => 'Slack Webhook URL is required.',
            ]);
            $this->saveModel();
        } catch (\Throwable $e) {
            $this->slackEnabled = false;
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
        return view('livewire.notifications.slack');
    }
}