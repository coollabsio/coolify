<?php

namespace App\Http\Livewire\Settings;

use App\Models\InstanceSettings;
use App\Notifications\TransactionalEmails\TestEmail;
use Illuminate\Support\Facades\Notification;
use Livewire\Component;

class Email extends Component
{
    public InstanceSettings $settings;

    protected $rules = [
        'settings.smtp_enabled' => 'nullable|boolean',
        'settings.smtp_host' => 'required',
        'settings.smtp_port' => 'required|numeric',
        'settings.smtp_encryption' => 'nullable',
        'settings.smtp_username' => 'nullable',
        'settings.smtp_password' => 'nullable',
        'settings.smtp_timeout' => 'nullable',
        'settings.smtp_test_recipients' => 'nullable',
        'settings.smtp_from_address' => 'required|email',
        'settings.smtp_from_name' => 'required',
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
        'settings.smtp_test_recipients' => 'Test Recipients',
    ];
    public function mount()
    {
        $this->decrypt();
    }
    public function instantSave()
    {
        try {
            $this->submit();
            $this->emit('success', 'Settings saved successfully.');
        } catch (\Exception $e) {
            $this->settings->smtp_enabled = false;
            $this->validate();
        }
    }
    public function testNotification()
    {
        $this->settings->notify(new TestEmail);
        $this->emit('success', 'Test email sent.');
    }
    private function decrypt()
    {
        if (data_get($this->settings, 'smtp_password')) {
            try {
                $this->settings->smtp_password = decrypt($this->settings->smtp_password);
            } catch (\Exception $e) {
            }
        }
    }
    public function submit()
    {
        $this->resetErrorBag();
        $this->validate();
        if ($this->settings->smtp_password) {
            $this->settings->smtp_password = encrypt($this->settings->smtp_password);
        } else {
            $this->settings->smtp_password = null;
        }

        $this->settings->save();
        $this->emit('success', 'Transaction email settings updated successfully.');
        $this->decrypt();
    }
}