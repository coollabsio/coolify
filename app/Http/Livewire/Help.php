<?php

namespace App\Http\Livewire;

use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Illuminate\Notifications\Messages\MailMessage;
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
        'subject' => 'required|min:3'
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
            $this->rateLimit(3, 60);
            $this->validate();
            $subscriptionType = auth()->user()?->subscription?->type() ?? 'Free';
            $debug = "Route: {$this->path}";
            $mail = new MailMessage();
            $mail->view(
                'emails.help',
                [
                    'description' => $this->description,
                    'debug' => $debug
                ]
            );
            $mail->subject("[HELP - {$subscriptionType}]: {$this->subject}");
            send_user_an_email($mail,  auth()->user()?->email, 'hi@coollabs.io');
            $this->emit('success', 'Your message has been sent successfully. <br>We will get in touch with you as soon as possible.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
    public function render()
    {
        return view('livewire.help')->layout('layouts.app');
    }
}
