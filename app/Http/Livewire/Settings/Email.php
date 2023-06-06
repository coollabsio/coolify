<?php

namespace App\Http\Livewire\Settings;

use App\Mail\TestTransactionalEmail;
use App\Models\InstanceSettings;
use Illuminate\Support\Facades\Mail;
use Livewire\Component;

class Email extends Component
{
    public InstanceSettings $settings;

    protected $rules = [
        'settings.smtp_host' => 'required',
        'settings.smtp_port' => 'required|numeric',
        'settings.smtp_encryption' => 'nullable',
        'settings.smtp_username' => 'nullable',
        'settings.smtp_password' => 'nullable',
        'settings.smtp_timeout' => 'nullable',
        'settings.smtp_recipients' => 'required',
        'settings.smtp_test_recipients' => 'nullable',
        'settings.smtp_from_address' => 'required|email',
        'settings.smtp_from_name' => 'required',
    ];
    public function test_email()
    {
        config()->set('mail.default', 'smtp');
        config()->set('mail.mailers.smtp', [
            "transport" => "smtp",
            "host" => $this->settings->smtp_host,
            "port" => $this->settings->smtp_port,
            "encryption" => $this->settings->smtp_encryption,
            "username" => $this->settings->smtp_username,
            "password" => $this->settings->smtp_password,
        ]);

        $this->send_email();
    }
    public function test_email_local()
    {
        config()->set('mail.default', 'smtp');
        config()->set('mail.mailers.smtp', [
            "transport" => "smtp",
            "host" => 'coolify-mail',
            "port" => 1025,
        ]);
        $this->send_email();
    }
    private function send_email()
    {
    }
    public function submit()
    {
        $this->validate();
        $this->settings->smtp_recipients = str_replace(' ', '', $this->settings->smtp_recipients);
        $this->settings->smtp_test_recipients = str_replace(' ', '', $this->settings->smtp_test_recipients);
        $this->settings->save();
    }
}
