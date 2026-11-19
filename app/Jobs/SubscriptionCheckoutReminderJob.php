<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SubscriptionCheckoutReminderJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public User $user, public string $checkoutUrl)
    {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        $mail = new MailMessage;
        $mail->view('emails.subscription-checkout-reminder', [
            'checkoutUrl' => $this->checkoutUrl,
        ]);
        $mail->subject('Coolify: Finish subscribing to Coolify Cloud');
        send_user_an_email($mail, $this->user->email);
    }
}
