<?php

namespace App\Notifications\Channels;

use App\Models\User;
use Exception;
use Illuminate\Mail\Message;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Mail;

class TransactionalEmailChannel
{
    public function send(User $notifiable, Notification $notification): void
    {
        $settings = instanceSettings();
        if (! data_get($settings, 'smtp_enabled') && ! data_get($settings, 'resend_enabled')) {
            return;
        }
        $email = $notifiable->email;
        if (! $email) {
            return;
        }
        $this->bootConfigs();
        $mailMessage = $notification->toMail($notifiable);
        Mail::send(
            [],
            [],
            fn (Message $message) => $message
                ->to($email)
                ->subject($mailMessage->subject)
                ->html((string) $mailMessage->render())
        );
    }

    private function bootConfigs(): void
    {
        $type = set_transanctional_email_settings();
        if (! $type) {
            throw new Exception('No email settings found.');
        }
    }
}
