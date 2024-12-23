<?php

namespace App\Livewire\Notifications;

use App\Models\EmailNotificationSettings;
use App\Models\Team;
use App\Notifications\Test;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Email extends Component
{
    protected $listeners = ['refresh' => '$refresh'];

    #[Locked]
    public Team $team;

    #[Locked]
    public EmailNotificationSettings $settings;

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

    #[Validate(['nullable', 'numeric', 'min:1', 'max:65535'])]
    public ?int $smtpPort = null;

    #[Validate(['nullable', 'string', 'in:starttls,tls,none'])]
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
    public bool $dockerCleanupSuccessEmailNotifications = false;

    #[Validate(['boolean'])]
    public bool $dockerCleanupFailureEmailNotifications = true;

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
            $this->settings = $this->team->emailNotificationSettings;
            $this->syncData();
            $this->testEmailAddress = auth()->user()->email;
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function syncData(bool $toModel = false)
    {
        if ($toModel) {
            $this->validate();
            $this->settings->smtp_enabled = $this->smtpEnabled;
            $this->settings->smtp_from_address = $this->smtpFromAddress;
            $this->settings->smtp_from_name = $this->smtpFromName;
            $this->settings->smtp_recipients = $this->smtpRecipients;
            $this->settings->smtp_host = $this->smtpHost;
            $this->settings->smtp_port = $this->smtpPort;
            $this->settings->smtp_encryption = $this->smtpEncryption;
            $this->settings->smtp_username = $this->smtpUsername;
            $this->settings->smtp_password = $this->smtpPassword;
            $this->settings->smtp_timeout = $this->smtpTimeout;

            $this->settings->resend_enabled = $this->resendEnabled;
            $this->settings->resend_api_key = $this->resendApiKey;

            $this->settings->use_instance_email_settings = $this->useInstanceEmailSettings;

            $this->settings->deployment_success_email_notifications = $this->deploymentSuccessEmailNotifications;
            $this->settings->deployment_failure_email_notifications = $this->deploymentFailureEmailNotifications;
            $this->settings->status_change_email_notifications = $this->statusChangeEmailNotifications;
            $this->settings->backup_success_email_notifications = $this->backupSuccessEmailNotifications;
            $this->settings->backup_failure_email_notifications = $this->backupFailureEmailNotifications;
            $this->settings->scheduled_task_success_email_notifications = $this->scheduledTaskSuccessEmailNotifications;
            $this->settings->scheduled_task_failure_email_notifications = $this->scheduledTaskFailureEmailNotifications;
            $this->settings->docker_cleanup_success_email_notifications = $this->dockerCleanupSuccessEmailNotifications;
            $this->settings->docker_cleanup_failure_email_notifications = $this->dockerCleanupFailureEmailNotifications;
            $this->settings->server_disk_usage_email_notifications = $this->serverDiskUsageEmailNotifications;
            $this->settings->server_reachable_email_notifications = $this->serverReachableEmailNotifications;
            $this->settings->server_unreachable_email_notifications = $this->serverUnreachableEmailNotifications;
            $this->settings->save();

        } else {
            $this->smtpEnabled = $this->settings->smtp_enabled;
            $this->smtpFromAddress = $this->settings->smtp_from_address;
            $this->smtpFromName = $this->settings->smtp_from_name;
            $this->smtpRecipients = $this->settings->smtp_recipients;
            $this->smtpHost = $this->settings->smtp_host;
            $this->smtpPort = $this->settings->smtp_port;
            $this->smtpEncryption = $this->settings->smtp_encryption;
            $this->smtpUsername = $this->settings->smtp_username;
            $this->smtpPassword = $this->settings->smtp_password;
            $this->smtpTimeout = $this->settings->smtp_timeout;

            $this->resendEnabled = $this->settings->resend_enabled;
            $this->resendApiKey = $this->settings->resend_api_key;

            $this->useInstanceEmailSettings = $this->settings->use_instance_email_settings;

            $this->deploymentSuccessEmailNotifications = $this->settings->deployment_success_email_notifications;
            $this->deploymentFailureEmailNotifications = $this->settings->deployment_failure_email_notifications;
            $this->statusChangeEmailNotifications = $this->settings->status_change_email_notifications;
            $this->backupSuccessEmailNotifications = $this->settings->backup_success_email_notifications;
            $this->backupFailureEmailNotifications = $this->settings->backup_failure_email_notifications;
            $this->scheduledTaskSuccessEmailNotifications = $this->settings->scheduled_task_success_email_notifications;
            $this->scheduledTaskFailureEmailNotifications = $this->settings->scheduled_task_failure_email_notifications;
            $this->dockerCleanupSuccessEmailNotifications = $this->settings->docker_cleanup_success_email_notifications;
            $this->dockerCleanupFailureEmailNotifications = $this->settings->docker_cleanup_failure_email_notifications;
            $this->serverDiskUsageEmailNotifications = $this->settings->server_disk_usage_email_notifications;
            $this->serverReachableEmailNotifications = $this->settings->server_reachable_email_notifications;
            $this->serverUnreachableEmailNotifications = $this->settings->server_unreachable_email_notifications;
        }
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

    public function saveModel()
    {
        $this->syncData(true);
        $this->dispatch('success', 'Email notifications settings updated.');
    }

    public function instantSave(?string $type = null)
    {
        try {
            $this->resetErrorBag();

            if ($type === 'SMTP') {
                $this->submitSmtp();
            } elseif ($type === 'Resend') {
                $this->submitResend();
            } else {
                $this->smtpEnabled = false;
                $this->resendEnabled = false;
                $this->saveModel();

                return;
            }
        } catch (\Throwable $e) {
            if ($type === 'SMTP') {
                $this->smtpEnabled = false;
            } elseif ($type === 'Resend') {
                $this->resendEnabled = false;
            }

            return handleError($e, $this);
        } finally {
            $this->dispatch('refresh');
        }
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
                'smtpEncryption' => 'required|string|in:starttls,tls,none',
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

            $this->settings->resend_enabled = false;
            $this->settings->use_instance_email_settings = false;
            $this->resendEnabled = false;
            $this->useInstanceEmailSettings = false;

            $this->settings->smtp_enabled = $this->smtpEnabled;
            $this->settings->smtp_from_address = $this->smtpFromAddress;
            $this->settings->smtp_from_name = $this->smtpFromName;
            $this->settings->smtp_host = $this->smtpHost;
            $this->settings->smtp_port = $this->smtpPort;
            $this->settings->smtp_encryption = $this->smtpEncryption;
            $this->settings->smtp_username = $this->smtpUsername;
            $this->settings->smtp_password = $this->smtpPassword;
            $this->settings->smtp_timeout = $this->smtpTimeout;

            $this->settings->save();
            $this->dispatch('success', 'SMTP settings updated.');
        } catch (\Throwable $e) {
            $this->smtpEnabled = false;

            return handleError($e);
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

            $this->settings->smtp_enabled = false;
            $this->settings->use_instance_email_settings = false;
            $this->smtpEnabled = false;
            $this->useInstanceEmailSettings = false;

            $this->settings->resend_enabled = $this->resendEnabled;
            $this->settings->resend_api_key = $this->resendApiKey;
            $this->settings->smtp_from_address = $this->smtpFromAddress;
            $this->settings->smtp_from_name = $this->smtpFromName;

            $this->settings->save();
            $this->dispatch('success', 'Resend settings updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
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
                    $this->team?->notify(new Test($this->testEmailAddress, 'email'));
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
