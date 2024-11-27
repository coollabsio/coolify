<?php

namespace App\Notifications;

use App\Notifications\Dto\DiscordMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Middleware\RateLimited;

class Test extends Notification implements ShouldQueue
{
    use Queueable;

    public $tries = 5;

    public function __construct(public ?string $emails = null)
    {
        $this->onQueue('high');
    }

    public function via(object $notifiable): array
    {
        return setNotificationChannels($notifiable, 'test');
    }

    public function middleware(object $notifiable, string $channel)
    {
        return match ($channel) {
            \App\Notifications\Channels\EmailChannel::class => [new RateLimited('email')],
            default => [],
        };
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage;
        $mail->subject('Coolify: Test Email');
        $mail->view('emails.test');

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        $message = new DiscordMessage(
            title: ':white_check_mark: Test Success',
            description: 'This is a test Discord notification from Coolify. :cross_mark: :warning: :information_source:',
            color: DiscordMessage::successColor(),
        );

        $message->addField(name: 'Dashboard', value: '[Link]('.base_url().')', inline: true);

        return $message;
    }

    public function toNtfy(): array
    {
        return [
            'title' => 'Coolify: Test Ntfy Notification',
            'message' => 'Coolify: This is a test Ntfy notification from Coolify.',
            'buttons' => 'view, Go to your dashboard, '.base_url().';',
            'emoji' => 'rocket',
        ];
    }

    public function toTelegram(): array
    {
        return [
            'message' => 'Coolify: This is a test Telegram notification from Coolify.',
            'buttons' => [
                [
                    'text' => 'Go to your dashboard',
                    'url' => base_url(),
                ],
            ],
        ];
    }
}
