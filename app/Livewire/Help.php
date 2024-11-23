<?php

namespace App\Livewire;

use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Http;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Help extends Component
{
    use WithRateLimiting;

    #[Validate(['required', 'min:10', 'max:1000'])]
    public string $description;

    #[Validate(['required', 'min:3'])]
    public string $subject;

    public function submit()
    {
        try {
            $this->validate();
            $this->rateLimit(3, 30);

            $settings = instanceSettings();
            $mail = new MailMessage;
            $mail->view(
                'emails.help',
                [
                    'description' => $this->description,
                ]
            );
            $mail->subject("[HELP]: {$this->subject}");
            $type = set_transanctional_email_settings($settings);

            // Sending feedback through Cloud API
            if ($type === false) {
                $url = 'https://app.coolify.io/api/feedback';
                Http::post($url, [
                    'content' => 'User: `'.auth()->user()?->email.'` with subject: `'.$this->subject.'` has the following problem: `'.$this->description.'`',
                ]);
            } else {
                send_user_an_email($mail, auth()->user()?->email, 'hi@coollabs.io');
            }
            $this->dispatch('success', 'Feedback sent.', 'We will get in touch with you as soon as possible.');
            $this->reset('description', 'subject');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.help')->layout('layouts.app');
    }
}

function set_transanctional_email_settings($settings = null)
{
    if (is_null($settings)) {
        $settings = instanceSettings();
    }

    if ($settings->resend_enabled) {
        config()->set('mail.default', 'resend');
        config()->set('resend.api_key', $settings->resend_api_key);

        return 'resend';
    }

    if ($settings->smtp_enabled) {
        config()->set('mail.default', 'smtp');
        config()->set('mail.mailers.smtp', [
            'transport' => 'smtp',
            'host' => $settings->smtp_host,
            'port' => $settings->smtp_port,
            'encryption' => $settings->smtp_encryption === 'none' ? null : $settings->smtp_encryption,
            'username' => $settings->smtp_username,
            'password' => $settings->smtp_password,
            'timeout' => $settings->smtp_timeout,
            'local_domain' => null,
        ]);

        return 'smtp';
    }

    return false;
}
