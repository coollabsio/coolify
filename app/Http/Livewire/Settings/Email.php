<?php

namespace App\Http\Livewire\Settings;

use App\Mail\TestTransactionalEmail;
use App\Models\InstanceSettings;
use App\Notifications\TestTransactionEmail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Component;

class Email extends Component
{
    public InstanceSettings $settings;

    protected $rules = [
        'settings.extra_attributes.smtp_host' => 'required',
        'settings.extra_attributes.smtp_port' => 'required|numeric',
        'settings.extra_attributes.smtp_encryption' => 'nullable',
        'settings.extra_attributes.smtp_username' => 'nullable',
        'settings.extra_attributes.smtp_password' => 'nullable',
        'settings.extra_attributes.smtp_timeout' => 'nullable',
        'settings.extra_attributes.smtp_recipients' => 'required',
        'settings.extra_attributes.smtp_test_recipients' => 'nullable',
        'settings.extra_attributes.smtp_from_address' => 'required|email',
        'settings.extra_attributes.smtp_from_name' => 'required',
    ];
    public function test_email()
    {
        Notification::send($this->settings, new TestTransactionEmail);
    }
    // public function test_email()
    // {
    //     config()->set('mail.default', 'smtp');
    //     config()->set('mail.mailers.smtp', [
    //         "transport" => "smtp",
    //         "host" => $this->settings->smtp_host,
    //         "port" => $this->settings->smtp_port,
    //         "encryption" => $this->settings->smtp_encryption,
    //         "username" => $this->settings->smtp_username,
    //         "password" => $this->settings->smtp_password,
    //     ]);

    //     $this->send_email();
    // }
    // public function test_email_local()
    // {
    //     config()->set('mail.default', 'smtp');
    //     config()->set('mail.mailers.smtp', [
    //         "transport" => "smtp",
    //         "host" => 'coolify-mail',
    //         "port" => 1025,
    //     ]);
    //     $this->send_email();
    // }
    // private function send_email()
    // {
    // }
    public function submit()
    {
        $this->validate();
        $this->settings->extra_attributes->smtp_recipients = str_replace(' ', '', $this->settings->extra_attributes->smtp_recipients);
        $this->settings->extra_attributes->smtp_test_recipients = str_replace(' ', '', $this->settings->extra_attributes->smtp_test_recipients);
        $this->settings->save();
    }
}
