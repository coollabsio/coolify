<?php

namespace App\Notifications\Database;

use App\Notifications\Channels\DiscordChannel;
use App\Notifications\Channels\TelegramChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DailyBackup extends Notification implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public function __construct(public $databases) {}

    public function via(object $notifiable): array
    {
        return [DiscordChannel::class, TelegramChannel::class, MailChannel::class];
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage();
        $mail->subject('Coolify: Daily backup statuses');
        $mail->view('emails.daily-backup', [
            'databases' => $this->databases,
        ]);

        return $mail;
    }

    public function toDiscord(): string
    {
        return 'Coolify: Daily backup statuses';
    }

    public function toTelegram(): array
    {
        $message = 'Coolify: Daily backup statuses';

        return [
            'message' => $message,
        ];
    }
}
