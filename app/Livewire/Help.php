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
            if (blank($type)) {
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
