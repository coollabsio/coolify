<?php

namespace App\Livewire;

use App\Livewire\Concerns\ManagesSmtpServers;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Notifications\TransactionalEmails\Test;
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

class SettingsEmail extends Component
{
    use AuthorizesRequests, ManagesSmtpServers;

    public InstanceSettings $settings;

    #[Locked]
    public Team $team;

    #[Validate(['boolean'])]
    public bool $smtpEnabled = false;

    #[Validate(['nullable', 'email'])]
    public ?string $smtpFromAddress = null;

    #[Validate(['nullable', 'string'])]
    public ?string $smtpFromName = null;

    #[Validate(['nullable', 'email'])]
    public ?string $notificationFromAddress = null;

    #[Validate(['nullable', 'string', 'max:255'])]
    public ?string $notificationFromName = null;

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

    #[Validate(['nullable', 'email'])]
    public ?string $testEmailAddress = null;

    public bool $testUsesNotificationSender = false;

    public function mount()
    {
        if (isInstanceAdmin() === false) {
            return redirect()->route('dashboard');
        }
        $this->settings = instanceSettings();
        $this->syncData();
        $this->team = auth()->user()->currentTeam();
        $this->testEmailAddress = auth()->user()->email;
    }

    private function syncData(bool $toModel = false): void
    {
        if ($toModel) {
            $this->validate();
            $this->validate(['smtpEhloDomain' => ['nullable', 'string', new ValidHostname]]);
            $this->settings->smtp_enabled = $this->smtpEnabled;
            $this->fillSmtpServerSettings();
            $this->settings->notification_from_address = $this->notificationFromAddress;
            $this->settings->notification_from_name = $this->notificationFromName;

            $this->settings->resend_enabled = $this->resendEnabled;
            $this->settings->resend_api_key = $this->resendApiKey;
            $this->settings->resend_from_address = $this->resendFromAddress;
            $this->settings->resend_from_name = $this->resendFromName;
            $this->saveEmailSettings();
        } else {
            $this->smtpEnabled = $this->settings->smtp_enabled;
            $this->smtpHost = $this->settings->smtp_host;
            $this->smtpPort = $this->settings->smtp_port;
            $this->smtpEncryption = $this->settings->smtp_encryption;
            $this->smtpUsername = $this->settings->smtp_username;
            $this->smtpPassword = $this->settings->smtp_password;
            $this->smtpTimeout = $this->settings->smtp_timeout;
            $this->smtpEhloDomain = $this->settings->smtp_ehlo_domain;
            $this->smtpFromAddress = $this->settings->smtp_from_address;
            $this->smtpFromName = $this->settings->smtp_from_name;
            $this->notificationFromAddress = $this->settings->notification_from_address;
            $this->notificationFromName = $this->settings->notification_from_name;

            $this->resendEnabled = $this->settings->resend_enabled;
            $this->resendFromAddress = $this->settings->resend_from_address;
            $this->resendFromName = $this->settings->resend_from_name;
            $this->resendApiKey = $this->settings->resend_api_key;

            $this->smtpServerName = $this->settings->smtp_server_name;
            $this->selectedSmtpServer = 'active';
            $this->emailProvider = $this->activeEmailProvider();
        }
    }

    /**
     * The provider that sends email now. It differs from $emailProvider until the selected provider is saved.
     */
    #[Computed]
    public function activeEmailProvider(): string
    {
        return enabled_email_provider($this->settings) ?? 'none';
    }

    public function submit()
    {
        try {
            $this->authorize('update', $this->settings);
            $this->resetErrorBag();
            $this->enableSelectedEmailProvider();
            $this->syncData(true);
            $this->dispatch('success', 'Transactional email settings updated.');
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

    public function toggleSmtp()
    {
        try {
            $this->authorize('update', $this->settings);
            $this->resetErrorBag();

            if ($this->smtpEnabled) {
                $this->smtpEnabled = false;
                $this->syncData(true);
                $this->dispatch('success', 'SMTP settings updated.');
            } else {
                $this->validateSmtpSettings();
                $this->smtpEnabled = true;
                $this->submitSmtp();
            }
        } catch (\Throwable $e) {
            $this->syncData();

            return handleError($e, $this);
        }
    }

    public function toggleResend()
    {
        try {
            $this->authorize('update', $this->settings);
            $this->resetErrorBag();

            if ($this->resendEnabled) {
                $this->resendEnabled = false;
                $this->syncData(true);
                $this->dispatch('success', 'Resend settings updated.');
            } else {
                $this->validateResendSettings();
                $this->resendEnabled = true;
                $this->submitResend();
            }
        } catch (\Throwable $e) {
            $this->syncData();

            return handleError($e, $this);
        }
    }

    public function submitSmtp()
    {
        try {
            $this->authorize('update', $this->settings);
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
        try {
            $this->authorize('update', $this->settings);
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
            $this->resendEnabled = false;

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

    /**
     * Save the instance email settings and record the changed field names (never their values).
     */
    protected function saveEmailSettings(): void
    {
        $changedFields = auditChangedFields($this->settings);
        $this->settings->save();
        $this->emailProvider = $this->activeEmailProvider();

        if ($changedFields === []) {
            return;
        }

        auditLog('ui.settings.email.updated', [
            'team_id' => null,
            'resource' => 'instance',
            'section' => 'email',
            'changed_fields' => $changedFields,
        ]);
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
            'smtpHost' => 'required|string',
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
            $this->authorize('update', $this->settings);
            $this->validate([
                'testEmailAddress' => 'required|email',
                'notificationFromAddress' => $this->testUsesNotificationSender ? 'required|email' : 'nullable|email',
                'notificationFromName' => 'nullable|string|max:255',
            ], [
                'notificationFromAddress.required' => 'Notification from address is required to test the notification sender.',
                'testEmailAddress.required' => 'Test email address is required.',
                'testEmailAddress.email' => 'Please enter a valid email address.',
            ]);

            // Send with the sender on the page, so that a changed sender can be tested before Save.
            if ($this->settings->resend_enabled) {
                $this->validateResendSettings();
                $this->settings->resend_from_address = $this->resendFromAddress;
                $this->settings->resend_from_name = $this->resendFromName;
            } else {
                $this->validateSmtpSettings();
                $this->settings->smtp_from_address = $this->smtpFromAddress;
                $this->settings->smtp_from_name = $this->smtpFromName;
            }
            $this->settings->notification_from_address = $this->notificationFromAddress;
            $this->settings->notification_from_name = $this->notificationFromName;
            $this->saveEmailSettings();

            $executed = RateLimiter::attempt(
                'test-email:'.$this->team->id,
                $perMinute = 0,
                function () {
                    $this->team?->notifyNow(new Test(
                        $this->testEmailAddress,
                        usesNotificationSender: $this->testUsesNotificationSender,
                    ));
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
}
