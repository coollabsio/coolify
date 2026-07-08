<?php

namespace App\Notifications\Channels;

use App\Models\User;
use App\Services\NotificationDeduplicator;
use Exception;
use Illuminate\Mail\Message;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Mail;

class TransactionalEmailChannel
{
    public function __construct(private NotificationDeduplicator $deduplicator) {}

    public function send(User $notifiable, Notification $notification): void
    {
        $settings = instanceSettings();
        if (! data_get($settings, 'smtp_enabled') && ! data_get($settings, 'resend_enabled')) {
            return;
        }

        // Check if notification has a custom recipient (for email changes)
        $email = property_exists($notification, 'newEmail') && $notification->newEmail
            ? $notification->newEmail
            : $notifiable->email;

        if (! $email) {
            return;
        }
        $this->bootConfigs();
        $mailMessage = $notification->toMail($notifiable);
        $renderedMail = (string) $mailMessage->render();

        if (! $this->deduplicator->shouldSend($notifiable, $notification, self::class, [$email], $mailMessage->subject, $renderedMail)) {
            return;
        }

        Mail::send(
            [],
            [],
            fn (Message $message) => $message
                ->to($email)
                ->subject($mailMessage->subject)
                ->html($renderedMail)
        );
    }

    private function bootConfigs(): void
    {
        $type = set_transanctional_email_settings();
        if (blank($type)) {
            throw new Exception('No email settings found.');
        }
    }
}
