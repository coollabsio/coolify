<?php

namespace App\Livewire;

use App\Models\InstanceSettings;
use App\Models\Team;
use App\Notifications\Test;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;

class SettingsEmail extends Component
{
    public InstanceSettings $settings;

    #[Locked]
    public Team $team;

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

    #[Validate(['nullable', 'email'])]
    public ?string $testEmailAddress = null;

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

    public function syncData(bool $toModel = false)
    {
        if ($toModel) {
            $this->validate();
            $this->settings->smtp_enabled = $this->smtpEnabled;
            $this->settings->smtp_host = $this->smtpHost;
            $this->settings->smtp_port = $this->smtpPort;
            $this->settings->smtp_encryption = $this->smtpEncryption;
            $this->settings->smtp_username = $this->smtpUsername;
            $this->settings->smtp_password = $this->smtpPassword;
            $this->settings->smtp_timeout = $this->smtpTimeout;
            $this->settings->smtp_from_address = $this->smtpFromAddress;
            $this->settings->smtp_from_name = $this->smtpFromName;

            $this->settings->resend_enabled = $this->resendEnabled;
            $this->settings->resend_api_key = $this->resendApiKey;
            $this->settings->save();
        } else {
            $this->smtpEnabled = $this->settings->smtp_enabled;
            $this->smtpHost = $this->settings->smtp_host;
            $this->smtpPort = $this->settings->smtp_port;
            $this->smtpEncryption = $this->settings->smtp_encryption;
            $this->smtpUsername = $this->settings->smtp_username;
            $this->smtpPassword = $this->settings->smtp_password;
            $this->smtpTimeout = $this->settings->smtp_timeout;
            $this->smtpFromAddress = $this->settings->smtp_from_address;
            $this->smtpFromName = $this->settings->smtp_from_name;

            $this->resendEnabled = $this->settings->resend_enabled;
            $this->resendApiKey = $this->settings->resend_api_key;
        }
    }

    public function submit()
    {
        try {
            $this->resetErrorBag();
            $this->syncData(true);
            $this->dispatch('success', 'Transactional email settings updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function instantSave(string $type)
    {
        try {
            $this->resetErrorBag();

            if ($type === 'SMTP') {
                $this->submitSmtp();
            } elseif ($type === 'Resend') {
                $this->submitResend();
            }

        } catch (\Throwable $e) {
            if ($type === 'SMTP') {
                $this->smtpEnabled = false;
            } elseif ($type === 'Resend') {
                $this->resendEnabled = false;
            }

            return handleError($e, $this);
        }
    }

    public function submitSmtp()
    {
        try {
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

            $this->resendEnabled = false;
            $this->settings->resend_enabled = false;

            $this->settings->smtp_enabled = $this->smtpEnabled;
            $this->settings->smtp_host = $this->smtpHost;
            $this->settings->smtp_port = $this->smtpPort;
            $this->settings->smtp_encryption = $this->smtpEncryption;
            $this->settings->smtp_username = $this->smtpUsername;
            $this->settings->smtp_password = $this->smtpPassword;
            $this->settings->smtp_timeout = $this->smtpTimeout;
            $this->settings->smtp_from_address = $this->smtpFromAddress;
            $this->settings->smtp_from_name = $this->smtpFromName;

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
            $this->settings->smtp_enabled = false;

            $this->settings->resend_enabled = $this->resendEnabled;
            $this->settings->resend_api_key = $this->resendApiKey;
            $this->settings->smtp_from_address = $this->smtpFromAddress;
            $this->settings->smtp_from_name = $this->smtpFromName;

            $this->settings->save();

            $this->dispatch('success', 'Resend settings updated.');
        } catch (\Throwable $e) {
            $this->resendEnabled = false;

            return handleError($e);
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
            return handleError($e);
        }
    }
}
