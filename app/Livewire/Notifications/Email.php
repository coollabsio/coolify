<?php

namespace App\Livewire\Notifications;

use App\Models\Team;
use App\Notifications\Test;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Email extends Component
{
    public Team $team;

    #[Locked]
    public string $emails;

    #[Validate(['boolean'])]
    public bool $smtpEnabled = false;

    #[Validate(['boolean'])]
    public bool $useInstanceEmailSettings = false;

    #[Validate(['nullable', 'email'])]
    public ?string $smtpFromAddress = null;

    #[Validate(['nullable', 'string'])]
    public ?string $smtpFromName = null;

    #[Validate(['nullable', 'string'])]
    public ?string $smtpRecipients = null;

    #[Validate(['nullable', 'string'])]
    public ?string $smtpHost = null;

    #[Validate(['nullable', 'numeric'])]
    public ?int $smtpPort = null;

    #[Validate(['nullable', 'string'])]
    public ?string $smtpEncryption = null;

    #[Validate(['nullable', 'string'])]
    public ?string $smtpUsername = null;

    #[Validate(['nullable', 'string'])]
    public ?string $smtpPassword = null;

    #[Validate(['nullable', 'numeric'])]
    public ?int $smtpTimeout = null;

    #[Validate(['boolean'])]
    public bool $smtpNotificationsTest;

    #[Validate(['boolean'])]
    public bool $smtpNotificationsDeployments;

    #[Validate(['boolean'])]
    public bool $smtpNotificationsStatusChanges;

    #[Validate(['boolean'])]
    public bool $smtpNotificationsDatabaseBackups;

    #[Validate(['boolean'])]
    public bool $smtpNotificationsScheduledTasks;

    #[Validate(['boolean'])]
    public bool $smtpNotificationsServerDiskUsage;

    #[Validate(['boolean'])]
    public bool $resendEnabled;

    #[Validate(['nullable', 'string'])]
    public ?string $resendApiKey = null;

    public function mount()
    {
        try {
            $this->team = auth()->user()->currentTeam();
            $this->emails = auth()->user()->email;
            $this->syncData();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function syncData(bool $toModel = false)
    {
        if ($toModel) {
            $this->validate();
            $this->team->smtp_enabled = $this->smtpEnabled;
            $this->team->smtp_from_address = $this->smtpFromAddress;
            $this->team->smtp_from_name = $this->smtpFromName;
            $this->team->smtp_host = $this->smtpHost;
            $this->team->smtp_port = $this->smtpPort;
            $this->team->smtp_encryption = $this->smtpEncryption;
            $this->team->smtp_username = $this->smtpUsername;
            $this->team->smtp_password = $this->smtpPassword;
            $this->team->smtp_timeout = $this->smtpTimeout;
            $this->team->smtp_recipients = $this->smtpRecipients;
            $this->team->smtp_notifications_test = $this->smtpNotificationsTest;
            $this->team->smtp_notifications_deployments = $this->smtpNotificationsDeployments;
            $this->team->smtp_notifications_status_changes = $this->smtpNotificationsStatusChanges;
            $this->team->smtp_notifications_database_backups = $this->smtpNotificationsDatabaseBackups;
            $this->team->smtp_notifications_scheduled_tasks = $this->smtpNotificationsScheduledTasks;
            $this->team->smtp_notifications_server_disk_usage = $this->smtpNotificationsServerDiskUsage;
            $this->team->use_instance_email_settings = $this->useInstanceEmailSettings;
            $this->team->resend_enabled = $this->resendEnabled;
            $this->team->resend_api_key = $this->resendApiKey;
            $this->team->save();
            refreshSession();
        } else {
            $this->smtpEnabled = $this->team->smtp_enabled;
            $this->smtpFromAddress = $this->team->smtp_from_address;
            $this->smtpFromName = $this->team->smtp_from_name;
            $this->smtpHost = $this->team->smtp_host;
            $this->smtpPort = $this->team->smtp_port;
            $this->smtpEncryption = $this->team->smtp_encryption;
            $this->smtpUsername = $this->team->smtp_username;
            $this->smtpPassword = $this->team->smtp_password;
            $this->smtpTimeout = $this->team->smtp_timeout;
            $this->smtpRecipients = $this->team->smtp_recipients;
            $this->smtpNotificationsTest = $this->team->smtp_notifications_test;
            $this->smtpNotificationsDeployments = $this->team->smtp_notifications_deployments;
            $this->smtpNotificationsStatusChanges = $this->team->smtp_notifications_status_changes;
            $this->smtpNotificationsDatabaseBackups = $this->team->smtp_notifications_database_backups;
            $this->smtpNotificationsScheduledTasks = $this->team->smtp_notifications_scheduled_tasks;
            $this->smtpNotificationsServerDiskUsage = $this->team->smtp_notifications_server_disk_usage;
            $this->useInstanceEmailSettings = $this->team->use_instance_email_settings;
            $this->resendEnabled = $this->team->resend_enabled;
            $this->resendApiKey = $this->team->resend_api_key;
        }
    }

    public function sendTestNotification()
    {
        try {
            $executed = RateLimiter::attempt(
                'test-email:'.$this->team->id,
                $perMinute = 0,
                function () {
                    $this->team?->notify(new Test($this->emails));
                    $this->dispatch('success', 'Test Email sent.');
                },
                $decaySeconds = 10,
            );

            if (! $executed) {
                throw new \Exception('Too many messages sent!');
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function instantSaveInstance()
    {
        try {
            $this->smtpEnabled = false;
            $this->resendEnabled = false;
            $this->saveModel();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function instantSaveSmtpEnabled()
    {
        try {
            $this->validate([
                'smtpHost' => 'required',
                'smtpPort' => 'required|numeric',
            ], [
                'smtpHost.required' => 'SMTP Host is required.',
                'smtpPort.required' => 'SMTP Port is required.',
            ]);
            $this->resendEnabled = false;
            $this->saveModel();
        } catch (\Throwable $e) {
            $this->smtpEnabled = false;

            return handleError($e, $this);
        }
    }

    public function instantSaveResend()
    {
        try {
            $this->validate([
            ], [
                'resendApiKey.required' => 'Resend API Key is required.',
            ]);
            $this->smtpEnabled = false;
            $this->saveModel();
        } catch (\Throwable $e) {
            $this->resendEnabled = false;

            return handleError($e, $this);
        }
    }

    public function saveModel()
    {
        $this->syncData(true);
        refreshSession();
        $this->dispatch('success', 'Settings saved.');
    }

    public function submit()
    {
        try {
            $this->resetErrorBag();
            $this->saveModel();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function copyFromInstanceSettings()
    {
        $settings = instanceSettings();

        if ($settings->smtp_enabled) {
            $this->smtpEnabled = true;
            $this->smtpFromAddress = $settings->smtp_from_address;
            $this->smtpFromName = $settings->smtp_from_name;
            $this->smtpRecipients = $settings->smtp_recipients;
            $this->smtpHost = $settings->smtp_host;
            $this->smtpPort = $settings->smtp_port;
            $this->smtpEncryption = $settings->smtp_encryption;
            $this->smtpUsername = $settings->smtp_username;
            $this->smtpPassword = $settings->smtp_password;
            $this->smtpTimeout = $settings->smtp_timeout;
            $this->resendEnabled = false;
            $this->saveModel();

            return;
        }
        if ($settings->resend_enabled) {
            $this->resendEnabled = true;
            $this->resendApiKey = $settings->resend_api_key;
            $this->smtpEnabled = false;
            $this->saveModel();

            return;
        }
        $this->dispatch('error', 'Instance SMTP/Resend settings are not enabled.');
    }

    public function render()
    {
        return view('livewire.notifications.email');
    }
}
