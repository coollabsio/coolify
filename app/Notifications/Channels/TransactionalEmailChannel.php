<?php

namespace App\Notifications\Channels;

use App\Models\User;
use Exception;
use Illuminate\Mail\Message;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Mail;
use Lettermint\Lettermint as LettermintClient;

class TransactionalEmailChannel
{
    public function send(User $notifiable, Notification $notification): void
    {
        $settings = instanceSettings();
        if (! data_get($settings, 'smtp_enabled') && ! data_get($settings, 'resend_enabled') && ! data_get($settings, 'lettermint_enabled')) {
            return;
        }
        $email = $notifiable->email;
        if (! $email) {
            return;
        }
        if (data_get($settings, 'lettermint_enabled')) {
            $from = "{$settings->smtp_from_name} <{$settings->smtp_from_address}>";
            $mailMessage = $notification->toMail($notifiable);
            $client = new LettermintClient($settings->lettermint_api_key);
            $client->email
                ->from($from)
                ->to($email)
                ->subject($mailMessage->subject)
                ->html((string) $mailMessage->render())
                ->send();
        } else {
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
    }

    private function bootConfigs(): void
    {
        $type = set_transanctional_email_settings();
        if (blank($type)) {
            throw new Exception('No email settings found.');
        }
    }
}
