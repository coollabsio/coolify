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

    #[Validate(['nullable', 'string', 'in:tls,ssl,none'])]
    public ?string $smtpEncryption = null;

    #[Validate(['nullable', 'string'])]
    public ?string $smtpUsername = null;

    #[Validate(['nullable', 'string'])]
    public ?string $smtpPassword = null;

    #[Validate(['nullable', 'numeric'])]
    public ?int $smtpTimeout = null;

    #[Validate(['boolean'])]
    public bool $resendEnabled = false;

    #[Validate(['nullable', 'string'])]
    public ?string $resendApiKey = null;

    #[Validate(['boolean'])]
    public bool $useInstanceEmailSettings = false;

    #[Validate(['boolean'])]
    public bool $deploymentSuccessEmailNotifications = false;

    #[Validate(['boolean'])]
    public bool $deploymentFailureEmailNotifications = true;

    #[Validate(['boolean'])]
    public bool $statusChangeEmailNotifications = false;

    #[Validate(['boolean'])]
    public bool $backupSuccessEmailNotifications = false;

    #[Validate(['boolean'])]
    public bool $backupFailureEmailNotifications = true;

    #[Validate(['boolean'])]
    public bool $scheduledTaskSuccessEmailNotifications = false;

    #[Validate(['boolean'])]
    public bool $scheduledTaskFailureEmailNotifications = true;

    #[Validate(['boolean'])]
    public bool $dockerCleanupEmailNotifications = false;

    #[Validate(['boolean'])]
    public bool $serverDiskUsageEmailNotifications = true;

    #[Validate(['boolean'])]
    public bool $serverReachableEmailNotifications = false;

    #[Validate(['boolean'])]
    public bool $serverUnreachableEmailNotifications = true;

    #[Validate(['nullable', 'email'])]
    public ?string $testEmailAddress = null;

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
            $settings = $this->team->emailNotificationSettings;
            $settings->smtp_enabled = $this->smtpEnabled;
            $settings->smtp_from_address = $this->smtpFromAddress;
            $settings->smtp_from_name = $this->smtpFromName;
            $settings->smtp_recipients = $this->smtpRecipients;
            $settings->smtp_host = $this->smtpHost;
            $settings->smtp_port = $this->smtpPort;
            $settings->smtp_encryption = $this->smtpEncryption;
            $settings->smtp_username = $this->smtpUsername;
            $settings->smtp_password = $this->smtpPassword;
            $settings->smtp_timeout = $this->smtpTimeout;

            $settings->resend_enabled = $this->resendEnabled;
            $settings->resend_api_key = $this->resendApiKey;

            $settings->use_instance_email_settings = $this->useInstanceEmailSettings;

            $settings->deployment_success_email_notifications = $this->deploymentSuccessEmailNotifications;
            $settings->deployment_failure_email_notifications = $this->deploymentFailureEmailNotifications;
            $settings->status_change_email_notifications = $this->statusChangeEmailNotifications;
            $settings->backup_success_email_notifications = $this->backupSuccessEmailNotifications;
            $settings->backup_failure_email_notifications = $this->backupFailureEmailNotifications;
            $settings->scheduled_task_success_email_notifications = $this->scheduledTaskSuccessEmailNotifications;
            $settings->scheduled_task_failure_email_notifications = $this->scheduledTaskFailureEmailNotifications;
            $settings->docker_cleanup_email_notifications = $this->dockerCleanupEmailNotifications;
            $settings->server_disk_usage_email_notifications = $this->serverDiskUsageEmailNotifications;
            $settings->server_reachable_email_notifications = $this->serverReachableEmailNotifications;
            $settings->server_unreachable_email_notifications = $this->serverUnreachableEmailNotifications;

            $settings->save();
            refreshSession();
        } else {
            $settings = $this->team->emailNotificationSettings;

            $this->smtpEnabled = $settings->smtp_enabled;
            $this->smtpFromAddress = $settings->smtp_from_address;
            $this->smtpFromName = $settings->smtp_from_name;
            $this->smtpRecipients = $settings->smtp_recipients;
            $this->smtpHost = $settings->smtp_host;
            $this->smtpPort = $settings->smtp_port;
            $this->smtpEncryption = $settings->smtp_encryption;
            $this->smtpUsername = $settings->smtp_username;
            $this->smtpPassword = $settings->smtp_password;
            $this->smtpTimeout = $settings->smtp_timeout;

            $this->resendEnabled = $settings->resend_enabled;
            $this->resendApiKey = $settings->resend_api_key;

            $this->useInstanceEmailSettings = $settings->use_instance_email_settings;

            $this->deploymentSuccessEmailNotifications = $settings->deployment_success_email_notifications;
            $this->deploymentFailureEmailNotifications = $settings->deployment_failure_email_notifications;
            $this->statusChangeEmailNotifications = $settings->status_change_email_notifications;
            $this->backupSuccessEmailNotifications = $settings->backup_success_email_notifications;
            $this->backupFailureEmailNotifications = $settings->backup_failure_email_notifications;
            $this->scheduledTaskSuccessEmailNotifications = $settings->scheduled_task_success_email_notifications;
            $this->scheduledTaskFailureEmailNotifications = $settings->scheduled_task_failure_email_notifications;
            $this->dockerCleanupEmailNotifications = $settings->docker_cleanup_email_notifications;
            $this->serverDiskUsageEmailNotifications = $settings->server_disk_usage_email_notifications;
            $this->serverReachableEmailNotifications = $settings->server_reachable_email_notifications;
            $this->serverUnreachableEmailNotifications = $settings->server_unreachable_email_notifications;
        }
    }

    public function sendTestEmail()
    {
        try {
            $this->validate([
                'testEmailAddress' => 'required|email',
            ], [
                'testEmailAddress.required' => 'Test email address is required.',
                'testEmailAddress.email' => 'Please enter a valid email address.',
            ]);

            $executed = RateLimiter::attempt(
                'test-email:'.$this->team->id,
                $perMinute = 0,
                function () {
                    $this->team?->notify(new Test($this->testEmailAddress));
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
                'smtpEnabled' => 'boolean',
                'smtpFromAddress' => 'required|email',
                'smtpFromName' => 'required|string',
                'smtpHost' => 'required|string',
                'smtpPort' => 'required|numeric',
                'smtpEncryption' => 'required|string|in:tls,ssl,none',
                'smtpUsername' => 'nullable|string',
                'smtpPassword' => 'nullable|string',
                'smtpTimeout' => 'nullable|numeric',
            ], [
                'smtpFromAddress.required' => 'From Address is required.',
                'smtpFromAddress.email' => 'Please enter a valid email address.',
                'smtpFromName.required' => 'From Name is required.',
                'smtpHost.required' => 'SMTP Host is required.',
                'smtpPort.required' => 'SMTP Port is required.',
                'smtpPort.numeric' => 'SMTP Port must be a number.',
                'smtpEncryption.required' => 'Encryption type is required.',
            ]);
            $this->resendEnabled = false;
            $this->useInstanceEmailSettings = false;
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
                'resendEnabled' => 'boolean',
                'resendApiKey' => 'required|string',
                'smtpFromAddress' => 'required|email',
                'smtpFromName' => 'required|string',
            ], [
                'resendApiKey.required' => 'Resend API Key is required.',
                'smtpFromAddress.required' => 'From Address is required.',
                'smtpFromAddress.email' => 'Please enter a valid email address.',
                'smtpFromName.required' => 'From Name is required.',
            ]);
            $this->smtpEnabled = false;
            $this->useInstanceEmailSettings = false;
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

    public function submitSmtp()
    {
        try {
            $this->resetErrorBag();
            $this->validate([
                'smtpEnabled' => 'boolean',
                'smtpFromAddress' => 'required|email',
                'smtpFromName' => 'required|string',
                'smtpHost' => 'required|string',
                'smtpPort' => 'required|numeric',
                'smtpEncryption' => 'required|string|in:tls,ssl,none',
                'smtpUsername' => 'nullable|string',
                'smtpPassword' => 'nullable|string',
                'smtpTimeout' => 'nullable|numeric',
            ], [
                'smtpFromAddress.required' => 'From Address is required.',
                'smtpFromAddress.email' => 'Please enter a valid email address.',
                'smtpFromName.required' => 'From Name is required.',
                'smtpHost.required' => 'SMTP Host is required.',
                'smtpPort.required' => 'SMTP Port is required.',
                'smtpPort.numeric' => 'SMTP Port must be a number.',
                'smtpEncryption.required' => 'Encryption type is required.',
            ]);

            $settings = $this->team->emailNotificationSettings;

            $settings->smtp_enabled = $this->smtpEnabled;
            $settings->smtp_from_address = $this->smtpFromAddress;
            $settings->smtp_from_name = $this->smtpFromName;
            $settings->smtp_host = $this->smtpHost;
            $settings->smtp_port = $this->smtpPort;
            $settings->smtp_encryption = $this->smtpEncryption;
            $settings->smtp_username = $this->smtpUsername;
            $settings->smtp_password = $this->smtpPassword;
            $settings->smtp_timeout = $this->smtpTimeout;

            $settings->save();
            refreshSession();
            $this->dispatch('success', 'SMTP settings saved.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function submitResend()
    {
        try {
            $this->resetErrorBag();
            $this->validate([
                'resendEnabled' => 'boolean',
                'resendApiKey' => 'required|string',
                'smtpFromAddress' => 'required|email',
                'smtpFromName' => 'required|string',
            ], [
                'resendApiKey.required' => 'Resend API Key is required.',
                'smtpFromAddress.required' => 'From Address is required.',
                'smtpFromAddress.email' => 'Please enter a valid email address.',
                'smtpFromName.required' => 'From Name is required.',
            ]);

            $settings = $this->team->emailNotificationSettings;

            $settings->resend_enabled = $this->resendEnabled;
            $settings->resend_api_key = $this->resendApiKey;
            $settings->smtp_from_address = $this->smtpFromAddress;
            $settings->smtp_from_name = $this->smtpFromName;

            $settings->save();
            refreshSession();
            $this->dispatch('success', 'Resend settings saved.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.notifications.email');
    }
}
