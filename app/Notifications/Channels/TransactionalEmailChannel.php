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
        $email = $notifiable->email;
        if (!$email) {
            return;
        }
        $settings = InstanceSettings::get();
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
        config()->set('mail.default', 'smtp');
        config()->set('mail.mailers.smtp', [
            "transport" => "smtp",
            "host" => $settings->extra_attributes?->get('smtp_host'),
            "port" => $settings->extra_attributes?->get('smtp_port'),
            "encryption" => $settings->extra_attributes?->get('smtp_encryption'),
            "username" => $settings->extra_attributes?->get('smtp_username'),
            "password" => $settings->extra_attributes?->get('smtp_password'),
            "timeout" => $settings->extra_attributes?->get('smtp_timeout'),
            "local_domain" => null,
        ]);
    }
}
