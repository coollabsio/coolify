<?php

namespace App\Livewire\Notifications;

use App\Livewire\Concerns\ManagesSmtpServers;
use App\Livewire\Notifications\Concerns\TogglesNotificationEvents;
use App\Models\EmailNotificationSettings;
use App\Models\Team;
use App\Notifications\Test;
use App\Rules\SafeSmtpHost;
use App\Rules\ValidHostname;
use App\Support\SmtpPresets;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Email extends Component
{
    use AuthorizesRequests, ManagesSmtpServers, TogglesNotificationEvents;

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
    public ?string $smtpPort = null;

    #[Validate(['nullable', 'string', 'in:starttls,tls,none'])]
    public ?string $smtpEncryption = 'starttls';

    #[Validate(['nullable', 'string'])]
    public ?string $smtpUsername = null;

    #[Validate(['nullable', 'string'])]
    public ?string $smtpPassword = null;

    #[Validate(['nullable', 'numeric'])]
    public ?string $smtpTimeout = null;

    #[Validate(['nullable', 'string'])]
    public ?string $smtpEhloDomain = null;

    #[Validate(['boolean'])]
    public bool $resendEnabled = false;

    #[Validate(['nullable', 'string'])]
    public ?string $resendApiKey = null;

    #[Validate(['nullable', 'email'])]
    public ?string $resendFromAddress = null;

    #[Validate(['nullable', 'string'])]
    public ?string $resendFromName = null;

    #[Validate(['required', 'in:none,smtp,resend'])]
    public string $emailProvider = 'none';

    #[Validate(['boolean'])]
    public bool $useInstanceEmailSettings = false;

    #[Validate(['boolean'])]
    public bool $deploymentSuccessEmailNotifications = false;

    #[Validate(['boolean'])]
    public bool $deploymentFailureEmailNotifications = true;

    #[Validate(['boolean'])]
    public bool $deploymentCommitDetailsEmailNotifications = false;

    #[Validate(['boolean'])]
    public bool $statusChangeEmailNotifications = false;

    #[Validate(['boolean'])]
    public bool $restartLimitReachedEmailNotifications = true;

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

    #[Validate(['boolean'])]
    public bool $serverPatchEmailNotifications = false;

    #[Validate(['boolean'])]
    public bool $traefikOutdatedEmailNotifications = true;

    #[Validate(['boolean'])]
    public bool $imageVulnerabilitiesEmailNotifications = true;

    #[Validate(['nullable', 'email'])]
    public ?string $testEmailAddress = null;

    public function mount()
    {
        try {
            $this->team = auth()->user()->currentTeam();
            $this->emails = auth()->user()->email;
            $this->settings = $this->team->emailNotificationSettings;
            $this->authorize('view', $this->settings);
            $this->syncData();
            $this->testEmailAddress = auth()->user()->email;
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    private function syncData(bool $toModel = false): void
    {
        if ($toModel) {
            $this->validate();
            $this->validate(['smtpEhloDomain' => ['nullable', 'string', new ValidHostname]]);
            if ($this->smtpHost !== $this->settings->smtp_host) {
                $this->validate(['smtpHost' => ['nullable', 'string', new SafeSmtpHost]]);
            }
            $this->settings->smtp_enabled = $this->smtpEnabled;
            $this->settings->smtp_recipients = $this->smtpRecipients;
            $this->fillSmtpServerSettings();

            $this->settings->resend_enabled = $this->resendEnabled;
            $this->settings->resend_api_key = $this->resendApiKey;
            $this->settings->resend_from_address = $this->resendFromAddress;
            $this->settings->resend_from_name = $this->resendFromName;

            $this->settings->use_instance_email_settings = $this->useInstanceEmailSettings;

            $this->settings->deployment_success_email_notifications = $this->deploymentSuccessEmailNotifications;
            $this->settings->deployment_failure_email_notifications = $this->deploymentFailureEmailNotifications;
            $this->settings->deployment_commit_details_email_notifications = $this->deploymentCommitDetailsEmailNotifications;
            $this->settings->status_change_email_notifications = $this->statusChangeEmailNotifications;
            $this->settings->restart_limit_reached_email_notifications = $this->restartLimitReachedEmailNotifications;
            $this->settings->backup_success_email_notifications = $this->backupSuccessEmailNotifications;
            $this->settings->backup_failure_email_notifications = $this->backupFailureEmailNotifications;
            $this->settings->scheduled_task_success_email_notifications = $this->scheduledTaskSuccessEmailNotifications;
            $this->settings->scheduled_task_failure_email_notifications = $this->scheduledTaskFailureEmailNotifications;
            $this->settings->docker_cleanup_success_email_notifications = $this->dockerCleanupSuccessEmailNotifications;
            $this->settings->docker_cleanup_failure_email_notifications = $this->dockerCleanupFailureEmailNotifications;
            $this->settings->server_disk_usage_email_notifications = $this->serverDiskUsageEmailNotifications;
            $this->settings->server_reachable_email_notifications = $this->serverReachableEmailNotifications;
            $this->settings->server_unreachable_email_notifications = $this->serverUnreachableEmailNotifications;
            $this->settings->server_patch_email_notifications = $this->serverPatchEmailNotifications;
            $this->settings->traefik_outdated_email_notifications = $this->traefikOutdatedEmailNotifications;
            $this->settings->image_vulnerabilities_email_notifications = $this->imageVulnerabilitiesEmailNotifications;
            $this->saveEmailSettings();

        } else {
            $this->smtpEnabled = $this->settings->smtp_enabled;
            $this->smtpFromAddress = $this->settings->smtp_from_address;
            $this->smtpFromName = $this->settings->smtp_from_name;
            $this->smtpRecipients = $this->settings->smtp_recipients;
            $this->smtpHost = $this->settings->smtp_host;
            $this->smtpPort = $this->settings->smtp_port;
            $this->smtpEncryption = $this->settings->smtp_encryption;
            $this->smtpUsername = $this->settings->smtp_username;
            $this->smtpPassword = auth()->user()->can('update', $this->settings)
                ? $this->settings->smtp_password
                : null;
            $this->smtpTimeout = $this->settings->smtp_timeout;
            $this->smtpEhloDomain = $this->settings->smtp_ehlo_domain;

            $this->resendEnabled = $this->settings->resend_enabled;
            $this->resendFromAddress = $this->settings->resend_from_address;
            $this->resendFromName = $this->settings->resend_from_name;
            $this->resendApiKey = auth()->user()->can('update', $this->settings)
                ? $this->settings->resend_api_key
                : null;

            $this->smtpServerName = $this->settings->smtp_server_name;
            $this->selectedSmtpServer = 'active';

            $this->useInstanceEmailSettings = $this->settings->use_instance_email_settings;
            $this->emailProvider = $this->activeEmailProvider();

            $this->deploymentSuccessEmailNotifications = $this->settings->deployment_success_email_notifications;
            $this->deploymentFailureEmailNotifications = $this->settings->deployment_failure_email_notifications;
            $this->deploymentCommitDetailsEmailNotifications = $this->settings->deployment_commit_details_email_notifications;
            $this->statusChangeEmailNotifications = $this->settings->status_change_email_notifications;
            $this->restartLimitReachedEmailNotifications = $this->settings->restart_limit_reached_email_notifications;
            $this->backupSuccessEmailNotifications = $this->settings->backup_success_email_notifications;
            $this->backupFailureEmailNotifications = $this->settings->backup_failure_email_notifications;
            $this->scheduledTaskSuccessEmailNotifications = $this->settings->scheduled_task_success_email_notifications;
            $this->scheduledTaskFailureEmailNotifications = $this->settings->scheduled_task_failure_email_notifications;
            $this->dockerCleanupSuccessEmailNotifications = $this->settings->docker_cleanup_success_email_notifications;
            $this->dockerCleanupFailureEmailNotifications = $this->settings->docker_cleanup_failure_email_notifications;
            $this->serverDiskUsageEmailNotifications = $this->settings->server_disk_usage_email_notifications;
            $this->serverReachableEmailNotifications = $this->settings->server_reachable_email_notifications;
            $this->serverUnreachableEmailNotifications = $this->settings->server_unreachable_email_notifications;
            $this->serverPatchEmailNotifications = $this->settings->server_patch_email_notifications;
            $this->traefikOutdatedEmailNotifications = $this->settings->traefik_outdated_email_notifications;
            $this->imageVulnerabilitiesEmailNotifications = $this->settings->image_vulnerabilities_email_notifications;
        }
    }

    public function submit()
    {
        try {
            $this->resetErrorBag();
            if (! $this->useInstanceEmailSettings) {
                $this->enableSelectedEmailProvider();
            }
            $this->saveModel();
        } catch (\Throwable $e) {
            $this->restoreSavedEmailProvider();

            return handleError($e, $this);
        }
    }

    /**
     * Turn on the provider selected in the provider list and save. When a required field of the
     * provider is empty, nothing is saved and the page only shows the fields to fill in.
     */
    public function selectEmailProvider()
    {
        try {
            $this->enableSelectedEmailProvider();
        } catch (ValidationException) {
            // Not complete yet: show the fields of the provider and the "Not active yet" notice, without an error.
            $this->resetErrorBag();

            return null;
        }

        return $this->submit();
    }

    /**
     * The provider that sends the team's email now. It differs from $emailProvider until the selected provider is saved.
     */
    #[Computed]
    public function activeEmailProvider(): string
    {
        return enabled_email_provider($this->settings) ?? 'none';
    }

    /**
     * Turn on the provider selected in the provider list. When its settings are not valid, the saved
     * provider stays on, so that a later save of another field cannot turn off the working provider.
     */
    private function enableSelectedEmailProvider(): void
    {
        $this->smtpEnabled = $this->emailProvider === 'smtp';
        $this->resendEnabled = $this->emailProvider === 'resend';

        try {
            $this->validateEnabledEmailProvider();
        } catch (ValidationException $e) {
            $this->restoreSavedEmailProvider();

            throw $e;
        }
    }

    private function restoreSavedEmailProvider(): void
    {
        $this->smtpEnabled = (bool) $this->settings->getOriginal('smtp_enabled');
        $this->resendEnabled = (bool) $this->settings->getOriginal('resend_enabled');
    }

    private function validateEnabledEmailProvider(): void
    {
        match (true) {
            $this->smtpEnabled => $this->validateSmtpSettings(),
            $this->resendEnabled => $this->validateResendSettings(),
            default => null,
        };
    }

    public function saveModel()
    {
        $this->authorize('update', $this->settings);

        $this->syncData(true);
        $this->dispatch('success', 'Email notifications settings updated.');
    }

    public function instantSaveDeploymentCommitDetails()
    {
        try {
            $this->resetErrorBag();
            $this->saveModel();
        } catch (\Throwable $e) {
            $this->deploymentCommitDetailsEmailNotifications = $this->settings->deployment_commit_details_email_notifications;

            return handleError($e, $this);
        }
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

    public function toggleSmtp()
    {
        try {
            $this->resetErrorBag();

            if ($this->smtpEnabled) {
                $this->smtpEnabled = false;
                $this->saveModel();
            } else {
                $this->validateSmtpSettings();
                $this->smtpEnabled = true;
                $this->submitSmtp();
            }
        } catch (\Throwable $e) {
            $this->syncData();

            return handleError($e, $this);
        } finally {
            $this->dispatch('refresh');
        }
    }

    public function toggleResend()
    {
        try {
            $this->resetErrorBag();

            if ($this->resendEnabled) {
                $this->resendEnabled = false;
                $this->saveModel();
            } else {
                $this->validateResendSettings();
                $this->resendEnabled = true;
                $this->submitResend();
            }
        } catch (\Throwable $e) {
            $this->syncData();

            return handleError($e, $this);
        } finally {
            $this->dispatch('refresh');
        }
    }

    public function submitSmtp()
    {
        $this->authorize('update', $this->settings);

        try {
            $this->resetErrorBag();
            $this->validateSmtpSettings();

            if ($this->smtpEnabled) {
                $this->disableEmailProvidersExcept('smtp');
            }

            $this->settings->smtp_enabled = $this->smtpEnabled;
            $this->fillSmtpServerSettings();

            $this->saveEmailSettings();
            $this->dispatch('success', 'SMTP settings updated.');
        } catch (\Throwable $e) {
            $this->smtpEnabled = false;

            return handleError($e, $this);
        }
    }

    public function submitResend()
    {
        $this->authorize('update', $this->settings);

        try {
            $this->resetErrorBag();
            $this->validateResendSettings();
            if ($this->resendEnabled) {
                $this->disableEmailProvidersExcept('resend');
            }

            $this->settings->resend_enabled = $this->resendEnabled;
            $this->settings->resend_api_key = $this->resendApiKey;
            $this->settings->resend_from_address = $this->resendFromAddress;
            $this->settings->resend_from_name = $this->resendFromName;

            $this->saveEmailSettings();
            $this->dispatch('success', 'Resend settings updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    /**
     * Turn off every email provider except the given one, so only one provider sends email.
     */
    private function disableEmailProvidersExcept(string $provider): void
    {
        if ($provider !== 'smtp') {
            $this->settings->smtp_enabled = $this->smtpEnabled = false;
        }
        if ($provider !== 'resend') {
            $this->settings->resend_enabled = $this->resendEnabled = false;
        }
    }

    protected function loadEmailSettings(): void
    {
        $this->syncData();
    }

    private function validateSmtpSettings(): void
    {
        $this->validate([
            'smtpEnabled' => 'boolean',
            'smtpServerName' => 'nullable|string|max:255',
            'smtpFromAddress' => 'required|email',
            'smtpFromName' => 'required|string',
            // A stored host is checked again at send time, so an unchanged host must not block saving other fields.
            'smtpHost' => $this->smtpHost === $this->settings->smtp_host ? ['required', 'string'] : ['required', 'string', new SafeSmtpHost],
            'smtpPort' => 'required|numeric',
            'smtpEncryption' => 'required|string|in:starttls,tls,none',
            'smtpUsername' => [Rule::requiredIf(SmtpPresets::requiresLogin($this->smtpHost)), 'nullable', 'string'],
            'smtpPassword' => [Rule::requiredIf(SmtpPresets::requiresLogin($this->smtpHost)), 'nullable', 'required_with:smtpUsername', 'string'],
            'smtpTimeout' => 'nullable|numeric',
            'smtpEhloDomain' => ['nullable', 'string', new ValidHostname],
        ], [
            'smtpFromAddress.required' => 'From Address is required.',
            'smtpFromAddress.email' => 'Please enter a valid email address.',
            'smtpFromName.required' => 'From Name is required.',
            'smtpHost.required' => 'SMTP Host is required.',
            'smtpPort.required' => 'SMTP Port is required.',
            'smtpPort.numeric' => 'SMTP Port must be a number.',
            'smtpUsername.required' => 'This provider needs a username.',
            'smtpPassword.required' => 'This provider needs a password.',
            'smtpPassword.required_with' => 'Enter the password for this username.',
            'smtpEncryption.required' => 'Encryption type is required.',
        ]);
    }

    private function validateResendSettings(): void
    {
        $this->validate([
            'resendEnabled' => 'boolean',
            'resendApiKey' => $this->resendEnabled ? 'required|string' : 'nullable|string',
            'resendFromAddress' => $this->resendEnabled ? 'required|email' : 'nullable|email',
            'resendFromName' => $this->resendEnabled ? 'required|string' : 'nullable|string',
        ], [
            'resendApiKey.required' => 'Resend API Key is required.',
            'resendFromAddress.required' => 'From Address is required.',
            'resendFromAddress.email' => 'Please enter a valid email address.',
            'resendFromName.required' => 'From Name is required.',
        ]);
    }

    public function sendTestEmail()
    {
        try {
            $this->authorize('sendTest', $this->settings);
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
                    $this->team?->notifyNow(new Test($this->testEmailAddress, 'email'));
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

    public function render()
    {
        return view('livewire.notifications.email');
    }

    /**
     * Save the settings, record the changed field names (never secret values), and show the saved provider.
     */
    protected function saveEmailSettings(): void
    {
        $changedFields = array_keys($this->settings->getDirty());
        $this->settings->save();
        $this->emailProvider = $this->activeEmailProvider();

        if ($changedFields !== []) {
            auditLog('ui.notifications.email.updated', [
                'team_id' => $this->team->id,
                'changed_fields' => array_values(array_diff($changedFields, ['smtp_password', 'resend_api_key'])),
            ]);
        }
    }
}
