<?php

namespace App\Notifications\Channels;

use App\Models\InstanceSettings;
use App\Models\User;
use Exception;
use Illuminate\Mail\Message;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Mail;
use Log;

class TransactionalEmailChannel
{
    private bool $isResend = false;
    public function send(User $notifiable, Notification $notification): void
    {
        $settings = InstanceSettings::get();
        if (!data_get($settings, 'smtp_enabled') && !data_get($settings, 'resend_enabled')) {
            Log::info('SMTP/Resend not enabled');
            return;
        }
        $email = $notifiable->email;
        if (!$email) {
            return;
        }
        $this->bootConfigs();
        $mailMessage = $notification->toMail($notifiable);
        // if ($this->isResend) {
        Mail::send(
            [],
            [],
            fn (Message $message) => $message
                ->from(
                    data_get($settings, 'smtp_from_address'),
                    data_get($settings, 'smtp_from_name'),
                )
                ->to($email)
                ->subject($mailMessage->subject)
                ->html((string)$mailMessage->render())
        );
        // } else {
        //     Mail::send(
        //         [],
        //         [],
        //         fn (Message $message) => $message
        //             ->from(
        //                 data_get($settings, 'smtp_from_address'),
        //                 data_get($settings, 'smtp_from_name'),
        //             )
        //             ->bcc($email)
        //             ->subject($mailMessage->subject)
        //             ->html((string)$mailMessage->render())
        //     );
        // }
    }

    private function bootConfigs(): void
    {
        $type = set_transanctional_email_settings();
        if (!$type) {
            throw new Exception('No email settings found.');
        }
        if ($type === 'resend') {
            $this->isResend = true;
        }
    }
}
