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
        'settings.smtp.enabled' => 'nullable|boolean',
        'settings.smtp.host' => 'required',
        'settings.smtp.port' => 'required|numeric',
        'settings.smtp.encryption' => 'nullable',
        'settings.smtp.username' => 'nullable',
        'settings.smtp.password' => 'nullable',
        'settings.smtp.timeout' => 'nullable',
        'settings.smtp.test_recipients' => 'nullable',
        'settings.smtp.from_address' => 'required|email',
        'settings.smtp.from_name' => 'required',
    ];
    protected $validationAttributes = [
        'settings.smtp.from_address' => 'From Address',
        'settings.smtp.from_name' => 'From Name',
        'settings.smtp.recipients' => 'Recipients',
        'settings.smtp.host' => 'Host',
        'settings.smtp.port' => 'Port',
        'settings.smtp.encryption' => 'Encryption',
        'settings.smtp.username' => 'Username',
        'settings.smtp.password' => 'Password',
        'settings.smtp.test_recipients' => 'Test Recipients',
    ];
    public function instantSave()
    {
        try {
            $this->submit();
        } catch (\Exception $e) {
            $this->settings->smtp->enabled = false;
            $this->validate();
        }
    }
    public function test_email()
    {
        Notification::send($this->settings, new TestEmail);
        $this->emit('success', 'Test email sent.');
    }
    public function submit()
    {
        $this->validate();
        $this->settings->smtp->test_recipients = str_replace(' ', '', $this->settings->smtp->test_recipients);
        $this->settings->save();
    }
}
