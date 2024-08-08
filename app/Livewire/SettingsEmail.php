<?php

namespace App\Livewire;

use App\Models\InstanceSettings;
use App\Notifications\TransactionalEmails\Test;
use Livewire\Component;

class SettingsEmail extends Component
{
    public InstanceSettings $settings;

    public string $emails;

    protected $rules = [
        'settings.smtp_enabled' => 'nullable|boolean',
        'settings.smtp_host' => 'required',
        'settings.smtp_port' => 'required|numeric',
        'settings.smtp_encryption' => 'nullable',
        'settings.smtp_username' => 'nullable',
        'settings.smtp_password' => 'nullable',
        'settings.smtp_timeout' => 'nullable',
        'settings.smtp_from_address' => 'required|email',
        'settings.smtp_from_name' => 'required',
        'settings.resend_enabled' => 'nullable|boolean',
        'settings.resend_api_key' => 'nullable',

    ];

    protected $validationAttributes = [
        'settings.smtp_from_address' => 'From Address',
        'settings.smtp_from_name' => 'From Name',
        'settings.smtp_recipients' => 'Recipients',
        'settings.smtp_host' => 'Host',
        'settings.smtp_port' => 'Port',
        'settings.smtp_encryption' => 'Encryption',
        'settings.smtp_username' => 'Username',
        'settings.smtp_password' => 'Password',
        'settings.smtp_timeout' => 'Timeout',
        'settings.resend_api_key' => 'Resend API Key',
    ];

    public function mount()
    {
        if (isInstanceAdmin()) {
            $this->settings = InstanceSettings::get();
            $this->emails = auth()->user()->email;
        } else {
            return redirect()->route('dashboard');
        }

    }

    public function submitFromFields()
    {
        try {
            $this->resetErrorBag();
            $this->validate([
                'settings.smtp_from_address' => 'required|email',
                'settings.smtp_from_name' => 'required',
            ]);
            $this->settings->save();
            $this->dispatch('success', 'Settings saved.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function submitResend()
    {
        try {
            $this->resetErrorBag();
            $this->validate([
                'settings.smtp_from_address' => 'required|email',
                'settings.smtp_from_name' => 'required',
                'settings.resend_api_key' => 'required',
            ]);
            $this->settings->save();
            $this->dispatch('success', 'Settings saved.');
        } catch (\Throwable $e) {
            $this->settings->resend_enabled = false;

            return handleError($e, $this);
        }
    }

    public function instantSaveResend()
    {
        try {
            $this->settings->smtp_enabled = false;
            $this->submitResend();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function instantSave()
    {
        try {
            $this->settings->resend_enabled = false;
            $this->submit();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function submit()
    {
        try {
            $this->resetErrorBag();
            $this->validate([
                'settings.smtp_from_address' => 'required|email',
                'settings.smtp_from_name' => 'required',
                'settings.smtp_host' => 'required',
                'settings.smtp_port' => 'required|numeric',
                'settings.smtp_encryption' => 'nullable',
                'settings.smtp_username' => 'nullable',
                'settings.smtp_password' => 'nullable',
                'settings.smtp_timeout' => 'nullable',
            ]);
            $this->settings->save();
            $this->dispatch('success', 'Settings saved.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function sendTestNotification()
    {
        $this->settings?->notify(new Test($this->emails));
        $this->dispatch('success', 'Test email sent.');
    }
}
