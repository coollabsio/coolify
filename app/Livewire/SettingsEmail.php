<?php

namespace App\Livewire;

use App\Models\InstanceSettings;
use Livewire\Attributes\Validate;
use Livewire\Component;

class SettingsEmail extends Component
{
    public InstanceSettings $settings;

    #[Validate(['boolean'])]
    public bool $smtpEnabled = false;

    #[Validate(['nullable', 'string'])]
    public ?string $smtpHost = null;

    #[Validate(['nullable', 'numeric', 'min:1', 'max:65535'])]
    public ?int $smtpPort = null;

    #[Validate(['nullable', 'string', 'in:tls,ssl,none'])]
    public ?string $smtpEncryption = null;

    #[Validate(['nullable', 'string'])]
    public ?string $smtpUsername = null;

    #[Validate(['nullable'])]
    public ?string $smtpPassword = null;

    #[Validate(['nullable', 'numeric'])]
    public ?int $smtpTimeout = null;

    #[Validate(['nullable', 'email'])]
    public ?string $smtpFromAddress = null;

    #[Validate(['nullable', 'string'])]
    public ?string $smtpFromName = null;

    #[Validate(['boolean'])]
    public bool $resendEnabled = false;

    #[Validate(['nullable', 'string'])]
    public ?string $resendApiKey = null;

    public function mount()
    {
        if (isInstanceAdmin() === false) {
            return redirect()->route('dashboard');
        }
        $this->settings = instanceSettings();
        $this->syncData();
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
            $this->dispatch('success', 'Settings saved.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function instantSave(string $type)
    {
        try {
            if ($type === 'SMTP') {
                $this->resendEnabled = false;
            } else {
                $this->smtpEnabled = false;
            }
            $this->syncData(true);
            if ($this->smtpEnabled || $this->resendEnabled) {
                $this->dispatch('success', "{$type} enabled.");
            } else {
                $this->dispatch('success', "{$type} disabled.");
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
