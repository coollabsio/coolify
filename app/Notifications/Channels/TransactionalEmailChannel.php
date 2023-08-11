<?php

namespace App\Notifications\Channels;

use App\Models\InstanceSettings;
use App\Models\User;
use Illuminate\Mail\Message;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Mail;

class TransactionalEmailChannel
{
    public function send(User $notifiable, Notification $notification): void
    {
        $settings = InstanceSettings::get();
        if (data_get($settings, 'smtp_enabled') !== true) {
            return;
        }
        $email = $notifiable->email;
        if (!$email) {
            return;
        }
        $this->bootConfigs();
        $mailMessage = $notification->toMail($notifiable);
        Mail::send(
            [],
            [],
            fn (Message $message) => $message
                ->from(
                    data_get($settings, 'smtp_from_address'),
                    data_get($settings, 'smtp_from_name')
                )
                ->to($email)
                ->subject($mailMessage->subject)
                ->html((string)$mailMessage->render())
        );
    }

    private function bootConfigs(): void
    {
        set_transanctional_email_settings();
    }
}
