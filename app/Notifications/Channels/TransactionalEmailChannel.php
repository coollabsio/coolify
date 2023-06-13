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
        if ($settings->extra_attributes?->get('smtp_active') !== true) {
            return;
        }
        $email = $notifiable->email;
        if (!$email) {
            return;
        }
        $this->bootConfigs($settings);
        $mailMessage = $notification->toMail($notifiable);

        Mail::send(
            [],
            [],
            fn (Message $message) => $message
                ->from(
                    $settings->extra_attributes?->get('smtp_from_address'),
                    $settings->extra_attributes?->get('smtp_from_name')
                )
                ->to($email)
                ->subject($mailMessage->subject)
                ->html((string)$mailMessage->render())
        );
    }

    private function bootConfigs(InstanceSettings $settings): void
    {
        set_transanctional_email_settings();
    }
}
