<?php

namespace App\Livewire;

use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Livewire\Component;

class Help extends Component
{
    use WithRateLimiting;

    public string $description;

    public string $subject;

    public ?string $path = null;

    protected $rules = [
        'description' => 'required|min:10',
        'subject' => 'required|min:3',
    ];

    public function mount()
    {
        $this->path = Route::current()?->uri() ?? null;
        if (isDev()) {
            $this->description = "I'm having trouble with {$this->path}";
            $this->subject = "Help with {$this->path}";
        }
    }

    public function submit()
    {
        try {
            $this->rateLimit(3, 30);
            $this->validate();
            $debug = "Route: {$this->path}";
            $mail = new MailMessage;
            $mail->view(
                'emails.help',
                [
                    'description' => $this->description,
                    'debug' => $debug,
                ]
            );
            $mail->subject("[HELP]: {$this->subject}");
            $settings = \App\Models\InstanceSettings::get();
            $type = set_transanctional_email_settings($settings);
            if (! $type) {
                $url = 'https://app.coolify.io/api/feedback';
                if (isDev()) {
                    $url = 'http://localhost:80/api/feedback';
                }
                Http::post($url, [
                    'content' => 'User: `'.auth()->user()?->email.'` with subject: `'.$this->subject.'` has the following problem: `'.$this->description.'`',
                ]);
            } else {
                send_user_an_email($mail, auth()->user()?->email, 'hi@coollabs.io');
            }
            $this->dispatch('success', 'Feedback sent.', 'We will get in touch with you as soon as possible.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.help')->layout('layouts.app');
    }
}
