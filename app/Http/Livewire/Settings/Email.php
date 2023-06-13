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
        'settings.extra_attributes.smtp_active' => 'nullable|boolean',
        'settings.extra_attributes.smtp_host' => 'required',
        'settings.extra_attributes.smtp_port' => 'required|numeric',
        'settings.extra_attributes.smtp_encryption' => 'nullable',
        'settings.extra_attributes.smtp_username' => 'nullable',
        'settings.extra_attributes.smtp_password' => 'nullable',
        'settings.extra_attributes.smtp_timeout' => 'nullable',
        'settings.extra_attributes.smtp_test_recipients' => 'nullable',
        'settings.extra_attributes.smtp_from_address' => 'required|email',
        'settings.extra_attributes.smtp_from_name' => 'required',
    ];
    protected $validationAttributes = [
        'settings.extra_attributes.smtp_from_address' => 'From Address',
        'settings.extra_attributes.smtp_from_name' => 'From Name',
        'settings.extra_attributes.smtp_recipients' => 'Recipients',
        'settings.extra_attributes.smtp_host' => 'Host',
        'settings.extra_attributes.smtp_port' => 'Port',
        'settings.extra_attributes.smtp_encryption' => 'Encryption',
        'settings.extra_attributes.smtp_username' => 'Username',
        'settings.extra_attributes.smtp_password' => 'Password',
        'settings.extra_attributes.smtp_test_recipients' => 'Test Recipients',
    ];
    public function instantSave()
    {
        try {
            $this->submit();
        } catch (\Exception $e) {
            $this->settings->extra_attributes->smtp_active = false;
            $this->validate();
        }
    }
    public function test_email()
    {
        Notification::send($this->settings, new TestEmail);
    }
    public function submit()
    {
        $this->validate();
        $this->settings->extra_attributes->smtp_test_recipients = str_replace(' ', '', $this->settings->extra_attributes->smtp_test_recipients);
        $this->settings->save();
    }
}
