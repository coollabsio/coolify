<?php

namespace App\Jobs;

use App\Models\InstanceSettings;
use App\Models\Waitlist;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Message;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendConfirmationForWaitlistJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $email, public string $uuid)
    {
    }

    public function handle()
    {
        try {
            $mail = new MailMessage();
            $confirmation_url = base_url() . '/webhooks/waitlist/confirm?email=' . $this->email . '&confirmation_code=' . $this->uuid;
            $cancel_url = base_url() . '/webhooks/waitlist/cancel?email=' . $this->email . '&confirmation_code=' . $this->uuid;
            $mail->view('emails.waitlist-confirmation',
                [
                    'confirmation_url' => $confirmation_url,
                    'cancel_url' => $cancel_url,
                ]);
            $mail->subject('You are on the waitlist!');
            send_user_an_email($mail, $this->email);
        } catch (\Throwable $e) {
            send_internal_notification("SendConfirmationForWaitlistJob failed for {$this->email} with error: " . $e->getMessage());
            ray($e->getMessage());
            throw $e;
        }
    }
}
