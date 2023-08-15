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
            $settings = InstanceSettings::get();


            set_transanctional_email_settings($settings);
            $mail = new MailMessage();

            $confirmation_url = base_url() . '/webhooks/waitlist/confirm?email=' . $this->email . '&confirmation_code=' . $this->uuid;
            $cancel_url = base_url() . '/webhooks/waitlist/cancel?email=' . $this->email . '&confirmation_code=' . $this->uuid;

            $mail->view('emails.waitlist-confirmation',
                [
                    'confirmation_url' => $confirmation_url,
                    'cancel_url' => $cancel_url,
                ]);
            $mail->subject('You are on the waitlist!');
            Mail::send(
                [],
                [],
                fn(Message $message) => $message
                    ->from(
                        data_get($settings, 'smtp_from_address'),
                        data_get($settings, 'smtp_from_name')
                    )
                    ->to($this->email)
                    ->subject($mail->subject)
                    ->html((string) $mail->render())
            );
        } catch (\Throwable $th) {
            ray($th->getMessage());
            throw $th;
        }
    }
}
